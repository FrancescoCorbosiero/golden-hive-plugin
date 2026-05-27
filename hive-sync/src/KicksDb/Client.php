<?php
declare(strict_types=1);

namespace HiveSync\KicksDb;

/**
 * HTTP client for the KicksDB v3 API (https://api.kicks.dev/v3).
 *
 * Scope (v1):
 *   - GET  /stockx/products/{identifier}  — by slug, UUID, or SKU.
 *                                           Falls back to /search on 404.
 *   - GET  /stockx/products               — query/search w/ pagination.
 *   - POST /stockx/prices                 — batch lookup, ≤ 50 SKUs/call.
 *
 * Not wired in v1: webhooks, variant-specific endpoints (the product
 * response carries variants inline for the IT market).
 *
 * Retry: exponential backoff (1s, 2s, 4s) on 429 / 5xx / network
 * failures across 4 attempts (3 retries after the initial). Worst-case
 * latency budget for one call is ~7s + 4 × timeout (default 30s), so
 * the caller's 25s deadline can still tolerate at least one full
 * exhausted retry cycle.
 * Rate-limit politeness: a 200ms sleep between batch /prices calls
 * (mirrors woo-importer's polite-client behaviour).
 *
 * Errors are returned as null + an error message in lastError(), so
 * the caller (Enricher / Source) can keep working through other items
 * instead of throwing.
 */
final class Client
{
    private const DEFAULT_BASE_URL = 'https://api.kicks.dev/v3';
    private const MAX_RETRIES      = 4;
    private const BATCH_DELAY_US   = 200_000;  // 200ms

    private string $lastError = '';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeout = 30,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * Look up a single product by SKU / slug / UUID. Tries the direct
     * /stockx/products/{identifier} endpoint first; falls back to a
     * search query on 404 — style codes like "DH5532-100" sometimes
     * need the search index to resolve when the slug form differs.
     */
    public function getProduct( string $identifier ): ?array
    {
        if ( ! $this->isConfigured() || $identifier === '' ) return null;

        $payload = $this->request( 'GET', '/stockx/products/' . rawurlencode( $identifier ) );
        if ( $payload !== null ) {
            return $this->extractProduct( $payload );
        }
        if ( $this->lastError !== 'not_found' ) {
            return null;  // network / 5xx / etc. — don't fall back to search
        }

        // 404 fallback: search by the identifier.
        $search = $this->request( 'GET', '/stockx/products', [ 'query' => $identifier, 'limit' => 5 ] );
        if ( ! $search ) return null;
        $rows = $search['data'] ?? $search['products'] ?? $search['hits'] ?? [];
        if ( ! is_array( $rows ) || ! $rows ) return null;

        // Prefer exact SKU/style-id match if the search returned siblings.
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $rowSku = (string) ( $row['sku'] ?? $row['style_id'] ?? '' );
            if ( $rowSku !== '' && strcasecmp( $rowSku, $identifier ) === 0 ) return $row;
        }
        return is_array( $rows[0] ) ? $rows[0] : null;
    }

    /**
     * Batch price lookup. Chunks SKUs into 50s and inserts 200ms gaps
     * between batches to stay polite under bursty load.
     *
     * @param string[] $skus
     * @return array<string, array>  sku → price snapshot
     */
    public function batchGetPrices( array $skus ): array
    {
        if ( ! $this->isConfigured() ) return [];
        $skus = array_values( array_unique( array_filter( array_map( 'strval', $skus ), fn( $s ) => $s !== '' ) ) );
        if ( ! $skus ) return [];

        $out = [];
        foreach ( array_chunk( $skus, 50 ) as $i => $chunk ) {
            if ( $i > 0 ) usleep( self::BATCH_DELAY_US );
            $resp = $this->request( 'POST', '/stockx/prices', null, [ 'skus' => $chunk ] );
            if ( ! is_array( $resp ) ) continue;
            $rows = $resp['data'] ?? $resp['prices'] ?? [];
            if ( ! is_array( $rows ) ) continue;
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) continue;
                $sku = (string) ( $row['sku'] ?? '' );
                if ( $sku !== '' ) $out[ $sku ] = $row;
            }
        }
        return $out;
    }

    /**
     * Search by free-text query (e.g. brand name + model). Used by
     * KicksDbSource's discovery mode.
     *
     * @return array<int, array>  raw search hits
     */
    public function search( string $query, int $page = 1, int $limit = 50 ): array
    {
        if ( ! $this->isConfigured() || $query === '' ) return [];
        $resp = $this->request( 'GET', '/stockx/products', [
            'query' => $query,
            'page'  => $page,
            'limit' => $limit,
        ] );
        if ( ! is_array( $resp ) ) return [];
        $rows = $resp['data'] ?? $resp['products'] ?? $resp['hits'] ?? [];
        return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : [];
    }

    private function extractProduct( array $payload ): ?array
    {
        if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) return $payload['data'];
        if ( isset( $payload['product'] ) && is_array( $payload['product'] ) ) return $payload['product'];
        // Some endpoints return the product as the top-level object.
        if ( isset( $payload['id'] ) || isset( $payload['sku'] ) || isset( $payload['slug'] ) ) {
            return $payload;
        }
        return null;
    }

    /**
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|null $body
     */
    private function request( string $method, string $path, ?array $query = null, ?array $body = null ): ?array
    {
        $this->lastError = '';
        if ( ! $this->isConfigured() ) {
            $this->lastError = 'no_api_key';
            return null;
        }

        $url = rtrim( $this->baseUrl, '/' ) . $path;
        if ( $query ) $url .= '?' . http_build_query( $query );

        $args = [
            'method'  => $method,
            'timeout' => $this->timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept'        => 'application/json',
            ],
        ];
        if ( $body !== null ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode( $body );
        }

        for ( $attempt = 0; $attempt < self::MAX_RETRIES; $attempt++ ) {
            if ( $attempt > 0 ) sleep( (int) pow( 2, $attempt - 1 ) );  // 1s, 2s, 4s
            $resp = wp_remote_request( $url, $args );
            if ( is_wp_error( $resp ) ) {
                $this->lastError = 'transport: ' . $resp->get_error_message();
                continue;  // retry
            }
            $code = (int) wp_remote_retrieve_response_code( $resp );
            if ( $code === 204 ) return [];
            if ( $code === 404 ) { $this->lastError = 'not_found'; return null; }
            if ( $code === 429 || $code >= 500 ) {
                $this->lastError = 'http_' . $code;
                continue;  // retry
            }
            if ( $code < 200 || $code >= 300 ) {
                $this->lastError = 'http_' . $code;
                return null;
            }
            $decoded = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
            if ( ! is_array( $decoded ) ) {
                $this->lastError = 'invalid_json';
                return null;
            }
            return $decoded;
        }
        return null;
    }
}
