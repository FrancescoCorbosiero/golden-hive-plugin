<?php
/**
 * KicksDB HTTP Client.
 *
 * KicksDB NON e un feed: e un servizio di lookup/enrichment per SKU noti +
 * search/discovery sull'intero universo StockX. Stessa pipeline di normalize/
 * diff/apply dei feed ma la selezione arriva da (a) SKU manuale, (b) search,
 * (c) refresh pricing batch.
 *
 * Parallelismo:
 * - Sliding-window curl_multi (concurrency default 8) per enrichment N-prodotti.
 *   Pattern identico a media-preimport.php: appena un handle completa, si
 *   aggiunge il prossimo dalla coda. Timeout per-request 10s (non 30s), errore
 *   veloce e meglio di un worker impiccato.
 * - Per il pricing si usa l'endpoint batch /stockx/prices (50 SKU per call)
 *   invece del per-SKU multi: un ordine di grandezza meno richieste.
 *
 * Retry:
 * - 3 tentativi con backoff esponenziale su 429 + 5xx + errori di rete.
 * - 4xx (tranne 429) NON si ritentano: risposta canonica dell'API.
 * - Retry-After (se presente) ha precedenza sul backoff.
 *
 * @see /samples — sample reali dell'API KicksDB StockX
 * @see includes/feeds/media-preimport.php — pattern curl_multi di riferimento
 */

defined( 'ABSPATH' ) || exit;

// Const SOPRA il guard: GH_KICKSDB_BASE_URL_DEFAULT e DEFAULT_CONCUR sono
// referenziate da settings.php (default settings); le altre internal ma
// uniformiamo per coerenza.
defined( 'GH_KICKSDB_BASE_URL_DEFAULT' ) || define( 'GH_KICKSDB_BASE_URL_DEFAULT', 'https://api.kicks.dev/v3' );
defined( 'GH_KICKSDB_DEFAULT_TIMEOUT' )  || define( 'GH_KICKSDB_DEFAULT_TIMEOUT',  10 );
defined( 'GH_KICKSDB_DEFAULT_CONCUR' )   || define( 'GH_KICKSDB_DEFAULT_CONCUR',   8 );
defined( 'GH_KICKSDB_MAX_ATTEMPTS' )     || define( 'GH_KICKSDB_MAX_ATTEMPTS',     3 );
defined( 'GH_KICKSDB_PRICES_CHUNK' )     || define( 'GH_KICKSDB_PRICES_CHUNK',     50 );

if ( function_exists( 'gh_kicksdb_base_url' ) ) return;

/**
 * Ritorna base URL (override via settings).
 */
function gh_kicksdb_base_url(): string {
    $s = gh_kicksdb_get_settings();
    $u = $s['base_url'] ?? '';
    return $u !== '' ? rtrim( $u, '/' ) : GH_KICKSDB_BASE_URL_DEFAULT;
}

/**
 * Header Authorization (Bearer API key) richiesto da tutti gli endpoint.
 *
 * @return array Array di header o array vuoto se API key mancante.
 */
function gh_kicksdb_auth_headers(): array {
    $s   = gh_kicksdb_get_settings();
    $key = (string) ( $s['api_key'] ?? '' );
    if ( $key === '' ) return [];
    return [
        'Authorization' => 'Bearer ' . $key,
        'Accept'        => 'application/json',
        'User-Agent'    => 'Mozilla/5.0 HiveCommerce/1.0 (+KicksDB)',
    ];
}

/**
 * Costruisce un URL KicksDB con query params codificati.
 */
function gh_kicksdb_build_url( string $path, array $query = [] ): string {
    $base = gh_kicksdb_base_url();
    $path = '/' . ltrim( $path, '/' );
    $url  = $base . $path;
    if ( ! empty( $query ) ) {
        $url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $query );
    }
    return $url;
}

// ── Single request (sincrono, con retry) ────────────────────

/**
 * Richiesta HTTP sincrona con retry.
 *
 * Wrappa wp_remote_request ma con:
 * - header auth KicksDB iniettati
 * - 3 tentativi con backoff esponenziale su 429/5xx/network
 * - rispetta Retry-After se presente
 *
 * @param array $args {
 *   @type string $path     Path relativo (es. '/stockx/products/DD1873-102').
 *   @type string $method   Default GET.
 *   @type array  $query    Query params.
 *   @type array  $body     Payload JSON (per POST/PUT/PATCH).
 *   @type array  $headers  Header aggiuntivi.
 *   @type int    $timeout  Default 10s.
 *   @type bool   $quiet_404 Se true, 404 non ritenta e non logga come errore.
 * }
 * @return array { status, body (decoded), raw_body, headers, duration_ms, attempts } | { error, status?, attempts }
 */
function gh_kicksdb_request( array $args ): array {

    $path      = (string) ( $args['path'] ?? '' );
    $method    = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
    $query     = (array) ( $args['query'] ?? [] );
    $body      = $args['body'] ?? null;
    $extra_hdr = (array) ( $args['headers'] ?? [] );
    $timeout   = (int) ( $args['timeout'] ?? GH_KICKSDB_DEFAULT_TIMEOUT );
    $quiet_404 = (bool) ( $args['quiet_404'] ?? false );

    if ( $path === '' ) {
        return [ 'error' => 'Path mancante.', 'attempts' => 0 ];
    }

    $auth = gh_kicksdb_auth_headers();
    if ( empty( $auth ) ) {
        return [ 'error' => 'KicksDB API key non configurata.', 'attempts' => 0 ];
    }

    $url     = gh_kicksdb_build_url( $path, $query );
    $headers = array_merge( $auth, $extra_hdr );

    $req = [
        'method'  => $method,
        'headers' => $headers,
        'timeout' => min( $timeout, 60 ),
    ];
    if ( $body !== null && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
        $req['body']                   = is_array( $body ) ? wp_json_encode( $body ) : (string) $body;
        $req['headers']['Content-Type'] = 'application/json';
    }

    $last_error = null;
    $status     = 0;
    $start      = microtime( true );

    for ( $attempt = 1; $attempt <= GH_KICKSDB_MAX_ATTEMPTS; $attempt++ ) {

        $resp = wp_remote_request( $url, $req );

        if ( is_wp_error( $resp ) ) {
            $last_error = $resp->get_error_message();
            gh_kicksdb_wait_backoff( $attempt, null );
            continue;
        }

        $status  = (int) wp_remote_retrieve_response_code( $resp );
        $raw     = (string) wp_remote_retrieve_body( $resp );
        $resp_hd = wp_remote_retrieve_headers( $resp );
        $hdrs    = is_object( $resp_hd ) && method_exists( $resp_hd, 'getAll' )
            ? $resp_hd->getAll()
            : (array) $resp_hd;

        // 404 quiet → ritorna subito senza retry
        if ( $status === 404 && $quiet_404 ) {
            return [
                'status'       => 404,
                'body'         => null,
                'raw_body'     => $raw,
                'headers'      => $hdrs,
                'duration_ms'  => (int) round( ( microtime( true ) - $start ) * 1000 ),
                'attempts'     => $attempt,
            ];
        }

        // Successo (2xx)
        if ( $status >= 200 && $status < 300 ) {
            $decoded = json_decode( $raw, true );
            return [
                'status'       => $status,
                'body'         => is_array( $decoded ) ? $decoded : null,
                'raw_body'     => $raw,
                'headers'      => $hdrs,
                'duration_ms'  => (int) round( ( microtime( true ) - $start ) * 1000 ),
                'attempts'     => $attempt,
            ];
        }

        // 4xx (non 429) → non ritentare: e una risposta canonica dell'API
        if ( $status >= 400 && $status < 500 && $status !== 429 ) {
            return [
                'status'       => $status,
                'body'         => null,
                'raw_body'     => $raw,
                'headers'      => $hdrs,
                'error'        => "HTTP {$status}",
                'duration_ms'  => (int) round( ( microtime( true ) - $start ) * 1000 ),
                'attempts'     => $attempt,
            ];
        }

        // 429 o 5xx → backoff + retry
        $last_error = "HTTP {$status}";
        gh_kicksdb_wait_backoff( $attempt, $hdrs['retry-after'] ?? ( $hdrs['Retry-After'] ?? null ) );
    }

    return [
        'error'    => $last_error ?? 'unknown error',
        'status'   => $status,
        'attempts' => GH_KICKSDB_MAX_ATTEMPTS,
    ];
}

/**
 * Attende con backoff esponenziale. Rispetta Retry-After se valido.
 */
function gh_kicksdb_wait_backoff( int $attempt, $retry_after ): void {
    // Retry-After puo essere secondi o HTTP-date. Gestiamo solo secondi interi.
    if ( $retry_after !== null && is_numeric( $retry_after ) ) {
        $sec = max( 1, min( 30, (int) $retry_after ) );
        sleep( $sec );
        return;
    }
    // Backoff: 1s, 2s, 4s
    $wait = min( 4, 1 << ( $attempt - 1 ) );
    sleep( $wait );
}

// ── Parallel multi-GET (sliding-window curl_multi) ──────────

/**
 * Fetcha in parallelo N path GET usando curl_multi con sliding window.
 *
 * Pattern identico a gh_preimport_download_batch: mantiene $concurrency
 * connessioni aperte; appena una completa, ne aggiunge un'altra dalla coda.
 * Tiene il pipe pieno invece di aspettare la richiesta piu lenta del batch.
 *
 * Niente retry interno (troppo complesso nel multi): i chiamanti rileggono
 * gli errori e rilanciano i soli falliti via gh_kicksdb_request sincrono.
 *
 * @param array $requests Array di { key, path, query? } — key identifica la
 *                        risposta nell'output (di solito lo SKU).
 * @param int   $concurrency Connessioni simultanee (default 8).
 * @param int   $timeout  Timeout per-request (default 10s).
 * @return array Mappa key → { status, body, raw_body, error?, duration_ms }
 */
function gh_kicksdb_request_multi( array $requests, int $concurrency = GH_KICKSDB_DEFAULT_CONCUR, int $timeout = GH_KICKSDB_DEFAULT_TIMEOUT ): array {

    if ( empty( $requests ) ) return [];

    $auth = gh_kicksdb_auth_headers();
    if ( empty( $auth ) ) {
        $err = [ 'error' => 'KicksDB API key non configurata.' ];
        return array_fill_keys( array_column( $requests, 'key' ), $err );
    }

    $concurrency = max( 1, min( 16, $concurrency ) );
    $timeout     = max( 3, min( 60, $timeout ) );

    // Header array come lista "Name: value"
    $header_lines = [];
    foreach ( $auth as $k => $v ) $header_lines[] = $k . ': ' . $v;

    $results = [];
    $queue   = array_values( $requests );
    $qi      = 0;
    $active  = []; // int(ch) → { ch, key, started }
    $mh      = curl_multi_init();

    $add_handle = function () use ( &$active, &$qi, &$queue, $mh, $header_lines, $timeout ) {
        if ( $qi >= count( $queue ) ) return;
        $r   = $queue[ $qi++ ];
        $url = gh_kicksdb_build_url( (string) ( $r['path'] ?? '' ), (array) ( $r['query'] ?? [] ) );
        $ch  = curl_init( $url );

        curl_setopt_array( $ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $header_lines,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 HiveCommerce/1.0 (+KicksDB)',
        ] );

        curl_multi_add_handle( $mh, $ch );
        $active[ (int) $ch ] = [
            'ch'      => $ch,
            'key'     => (string) ( $r['key'] ?? $url ),
            'started' => microtime( true ),
        ];
    };

    // Riempi finestra iniziale
    for ( $i = 0; $i < $concurrency && $qi < count( $queue ); $i++ ) {
        $add_handle();
    }

    // Loop
    while ( ! empty( $active ) ) {
        $status = curl_multi_exec( $mh, $running );
        if ( $running ) {
            curl_multi_select( $mh, 0.5 );
        }

        while ( $info = curl_multi_info_read( $mh ) ) {
            $ch  = $info['handle'];
            $key = (int) $ch;
            if ( ! isset( $active[ $key ] ) ) continue;

            $h        = $active[ $key ];
            $http     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $cerr     = curl_error( $ch );
            $body_raw = curl_multi_getcontent( $ch );
            $dur_ms   = (int) round( ( microtime( true ) - $h['started'] ) * 1000 );

            curl_multi_remove_handle( $mh, $ch );
            curl_close( $ch );
            unset( $active[ $key ] );

            if ( $cerr || $http === 0 ) {
                $results[ $h['key'] ] = [
                    'error'       => $cerr ?: 'network error',
                    'status'      => 0,
                    'duration_ms' => $dur_ms,
                ];
            } else {
                $decoded = json_decode( (string) $body_raw, true );
                $entry   = [
                    'status'      => $http,
                    'body'        => is_array( $decoded ) ? $decoded : null,
                    'raw_body'    => (string) $body_raw,
                    'duration_ms' => $dur_ms,
                ];
                if ( $http < 200 || $http >= 300 ) {
                    $entry['error'] = "HTTP {$http}";
                }
                $results[ $h['key'] ] = $entry;
            }

            // Refill: aggiungi il prossimo dalla coda
            $add_handle();
        }
    }

    curl_multi_close( $mh );

    // Preserva l'ordine delle keys originali
    $ordered = [];
    foreach ( $requests as $r ) {
        $k = (string) ( $r['key'] ?? '' );
        $ordered[ $k ] = $results[ $k ] ?? [ 'error' => 'no response' ];
    }
    return $ordered;
}

// ── High-level endpoint wrappers ────────────────────────────

/**
 * GET /v3/stockx/products/{sku}?display[variants|traits|identifiers]=true
 *
 * "Trick" del display: con questi flag l'endpoint ritorna metadata + variants
 * + traits + identifiers in UN SOLO round-trip. Senza display dovresti fare
 * 2 chiamate (products/{sku} poi products/{id}/variants). Dimezzi la latenza
 * e il consumo di rate-limit.
 *
 * @param string $sku
 * @param string $market Default dalla settings (es. 'IT').
 * @return array Response (vedi gh_kicksdb_request).
 */
function gh_kicksdb_get_product_full( string $sku, string $market = '' ): array {
    if ( $sku === '' ) return [ 'error' => 'SKU vuoto.' ];

    $market = $market !== '' ? $market : gh_kicksdb_default_market();

    return gh_kicksdb_request( [
        'path'      => '/stockx/products/' . rawurlencode( $sku ),
        'query'     => [
            'display[variants]'    => 'true',
            'display[traits]'      => 'true',
            'display[identifiers]' => 'true',
            'market'               => $market,
        ],
        'quiet_404' => true, // enrichment: 404 significa "non trovato", non errore
    ] );
}

/**
 * Versione parallelizzata di get_product_full per N SKU.
 *
 * @param string[] $skus
 * @param string   $market
 * @param int      $concurrency
 * @return array Mappa sku → response.
 */
function gh_kicksdb_get_products_multi( array $skus, string $market = '', int $concurrency = GH_KICKSDB_DEFAULT_CONCUR ): array {
    $skus   = array_values( array_filter( array_unique( array_map( 'strval', $skus ) ) ) );
    if ( empty( $skus ) ) return [];

    $market = $market !== '' ? $market : gh_kicksdb_default_market();

    $requests = [];
    foreach ( $skus as $sku ) {
        $requests[] = [
            'key'   => $sku,
            'path'  => '/stockx/products/' . rawurlencode( $sku ),
            'query' => [
                'display[variants]'    => 'true',
                'display[traits]'      => 'true',
                'display[identifiers]' => 'true',
                'market'               => $market,
            ],
        ];
    }

    return gh_kicksdb_request_multi( $requests, $concurrency );
}

/**
 * GET /v3/stockx/products — search/discovery.
 *
 * @param array $params {
 *   @type string $query   Stringa di ricerca libera.
 *   @type string $brand   Filtro brand (opzionale, via filters).
 *   @type int    $limit   Default 50.
 *   @type int    $page    Default 1.
 *   @type string $sort    'release_date' | 'rank' | '' (default ranking).
 *   @type string $order   'asc' | 'desc'.
 *   @type string $market  Default settings.
 * }
 * @return array Response.
 */
function gh_kicksdb_search_products( array $params ): array {

    $query = [
        'limit'  => (int) ( $params['limit'] ?? 50 ),
        'page'   => (int) ( $params['page'] ?? 1 ),
        'market' => (string) ( $params['market'] ?? gh_kicksdb_default_market() ),
    ];

    if ( ! empty( $params['query'] ) )  $query['query'] = (string) $params['query'];
    if ( ! empty( $params['sort'] ) )   $query['sort']  = (string) $params['sort'];
    if ( ! empty( $params['order'] ) )  $query['order'] = (string) $params['order'];
    if ( ! empty( $params['brand'] ) )  $query['filters'] = 'brand:' . (string) $params['brand'];

    return gh_kicksdb_request( [
        'path'  => '/stockx/products',
        'query' => $query,
    ] );
}

/**
 * POST /v3/stockx/prices — batch pricing per SKU (fino a 50 per call).
 *
 * Chunking automatico + accumula i risultati in una singola risposta.
 * GOTCHA: la risposta contiene piu righe per variante, ciascuna con un 'type'
 * diverso (standard | express_standard | express_expedited). Il consumer
 * DEVE filtrare type === 'standard' e prendere il MIN(price) per taglia
 * (lowest ask). Vedi gh_kicksdb_extract_standard_prices().
 *
 * @param string[] $skus
 * @param string   $market Default dalla settings.
 * @return array {
 *   @type array[] $data  Tutte le righe concatenate: { product_id, sku, variants[] }.
 *   @type int     $chunks Numero di chunk richiesti.
 *   @type int     $errors Numero di chunk falliti.
 *   @type array   $failed_chunks Array di chunk falliti (per retry).
 * }
 */
function gh_kicksdb_get_prices_batch( array $skus, string $market = '' ): array {

    $skus   = array_values( array_filter( array_unique( array_map( 'strval', $skus ) ) ) );
    if ( empty( $skus ) ) {
        return [ 'data' => [], 'chunks' => 0, 'errors' => 0, 'failed_chunks' => [] ];
    }

    $market = $market !== '' ? $market : gh_kicksdb_default_market();
    $chunks = array_chunk( $skus, GH_KICKSDB_PRICES_CHUNK );

    $all    = [];
    $errors = 0;
    $failed = [];

    foreach ( $chunks as $idx => $chunk ) {

        $resp = gh_kicksdb_request( [
            'path'   => '/stockx/prices',
            'method' => 'POST',
            'body'   => [
                'market' => $market,
                'skus'   => array_values( $chunk ),
            ],
        ] );

        if ( ! empty( $resp['error'] ) ) {
            $errors++;
            $failed[] = $chunk;
            continue;
        }

        $rows = $resp['body']['data'] ?? [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) $all[] = $row;
        }

        // Pacing tra chunk per evitare throttling lato KicksDB.
        // All'interno del chunk gia facciamo 50 lookup in 1 call (parallelismo
        // server-side), quindi il gap e solo TRA chunk.
        if ( count( $chunks ) > 1 && $idx < count( $chunks ) - 1 ) {
            usleep( 200_000 ); // 200ms
        }
    }

    return [
        'data'          => $all,
        'chunks'        => count( $chunks ),
        'errors'        => $errors,
        'failed_chunks' => $failed,
    ];
}

/**
 * Ritorna il market di default dalle settings (es. 'IT').
 */
function gh_kicksdb_default_market(): string {
    $s = gh_kicksdb_get_settings();
    return (string) ( $s['market'] ?? 'IT' );
}
