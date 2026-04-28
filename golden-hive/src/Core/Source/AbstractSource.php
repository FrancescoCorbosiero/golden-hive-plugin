<?php
declare(strict_types=1);

namespace GH\Core\Source;

/**
 * Base class concrete Sources extend. Provides the cross-cutting helpers
 * that previously lived (duplicated) inside each feed-*.php file.
 *
 * Implemented now (pure PHP, unit-tested):
 *   - validateConfig()        : enforces required + type from configSchema()
 *   - applyWithBinarySplit()  : retry on failure, recursively split batch
 *
 * Implemented as thin wrappers over legacy globals (function_exists guards
 * keep the base class loadable without WP for unit tests):
 *   - recordProvenance()      : gh_conflict_record_source(...)
 *   - resolveConflict()       : gh_conflict_resolve(...)
 *
 * Intentionally NOT yet implemented (Batch 4 — when first concrete Source
 * lands and we know exactly what surface it needs):
 *   - preCreateTaxonomyTerms(): scan items, create unique terms once, cache map
 *   - sideloadImages()        : wraps media-preimport sliding-window curl_multi
 *
 * Concrete sources MUST extend this class, not implement Source directly.
 * That guarantees future cross-cutting concerns reach every source by
 * editing one place.
 */
abstract class AbstractSource implements Source
{
    abstract public function id(): string;

    abstract public function label(): string;

    abstract public function capabilities(): SourceCapabilities;

    abstract public function configSchema(): array;

    abstract public function fetch(FetchRequest $request, Context $ctx): FetchResult;

    abstract public function diff(array $items, Context $ctx): Diff;

    abstract public function materialize(FeedItem $item, Context $ctx): MaterializeResult;

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Validate a config payload against this source's configSchema().
     * Returns a normalized array on success, or an array of error messages
     * keyed by field on failure.
     *
     * @return array{ok: bool, config?: array, errors?: array<string, string>}
     */
    protected function validateConfig(array $config): array
    {
        $schema = $this->configSchema();
        $clean = [];
        $errors = [];

        foreach ($schema as $field => $spec) {
            $type = (string) ($spec['type'] ?? 'text');
            $required = (bool) ($spec['required'] ?? false);
            $max = isset($spec['max']) ? (int) $spec['max'] : null;
            $value = $config[$field] ?? null;

            if ($value === null || $value === '') {
                if ($required) {
                    $errors[$field] = 'required';
                    continue;
                }
                $clean[$field] = $value === null ? '' : $value;
                continue;
            }

            // Type coercion / validation
            switch ($type) {
                case 'url':
                    if (! is_string($value) || ! preg_match('#^https?://#i', $value)) {
                        $errors[$field] = 'invalid_url';
                        continue 2;
                    }
                    break;
                case 'enum':
                    $options = (array) ($spec['options'] ?? []);
                    if (! in_array($value, $options, true)) {
                        $errors[$field] = 'invalid_option';
                        continue 2;
                    }
                    break;
                case 'int':
                    if (! is_numeric($value)) {
                        $errors[$field] = 'invalid_int';
                        continue 2;
                    }
                    $value = (int) $value;
                    break;
                case 'bool':
                    $value = (bool) $value;
                    break;
                case 'secret':
                case 'text':
                default:
                    $value = (string) $value;
            }

            if ($max !== null && is_string($value) && strlen($value) > $max) {
                $errors[$field] = 'too_long';
                continue;
            }

            $clean[$field] = $value;
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        return ['ok' => true, 'config' => $clean];
    }

    /**
     * Apply $applyBatch to $items. If the callable throws or returns false,
     * recursively split the batch in half and retry — isolating bad items
     * down to singletons before giving up.
     *
     * Hardening priority #1 ("retry with binary split"): a single bad
     * record in a batch of 25 no longer poisons the other 24.
     *
     * The callable receives an array of items and returns an array of
     * per-item results (any shape — collected verbatim into 'ok'). On
     * failure it must throw a Throwable; returning [] is treated as success.
     *
     * @param mixed[]                                    $items
     * @param callable(mixed[]): mixed[]                 $applyBatch
     * @return array{ok: mixed[], failed: array<int, array{item: mixed, error: string}>}
     */
    protected function applyWithBinarySplit(
        array $items,
        callable $applyBatch,
        int $minBatch = 1,
        int $maxDepth = 8,
    ): array {
        $results = ['ok' => [], 'failed' => []];
        if (! $items) {
            return $results;
        }

        // Iterative DFS so we don't blow the call stack on huge batches.
        $stack = [[$items, 0]];
        while ($stack) {
            [$batch, $depth] = array_pop($stack);
            if (! $batch) {
                continue;
            }

            try {
                $batchResults = $applyBatch($batch);
                foreach ((array) $batchResults as $r) {
                    $results['ok'][] = $r;
                }
                continue;
            } catch (\Throwable $e) {
                $count = count($batch);
                if ($count <= $minBatch || $depth >= $maxDepth) {
                    foreach ($batch as $item) {
                        $results['failed'][] = ['item' => $item, 'error' => $e->getMessage()];
                    }
                    continue;
                }
                $half = (int) ceil($count / 2);
                $left = array_slice($batch, 0, $half);
                $right = array_slice($batch, $half);
                // Push right first so left runs first (preserves order in 'ok').
                $stack[] = [$right, $depth + 1];
                $stack[] = [$left, $depth + 1];
            }
        }

        return $results;
    }

    /**
     * Record provenance after a successful materialize.
     * No-op if the conflict module is not loaded (unit-test safe).
     *
     * @param array<string, string> $sliceOwners e.g. ['pricing' => 'goldensneakers']
     */
    protected function recordProvenance(int $productId, string $source, array $sliceOwners = []): void
    {
        if ($productId <= 0) {
            return;
        }
        if (function_exists('gh_conflict_record_source')) {
            \gh_conflict_record_source($productId, $source, $sliceOwners);
        }
    }

    /**
     * Ask the conflict engine which slices we may write. When the conflict
     * module is unavailable, default-allow (legacy behavior preserved).
     *
     * @return array{allowed_slices: array<string,bool>, blocked: array<string,string>, applied_rule: ?string}
     */
    protected function resolveConflict(int $productId, array $incoming, string $sourceId): array
    {
        if (function_exists('gh_conflict_resolve')) {
            $res = \gh_conflict_resolve($productId, $incoming, $sourceId);
            return [
                'allowed_slices' => (array) ($res['allowed_slices'] ?? []),
                'blocked'        => (array) ($res['blocked'] ?? []),
                'applied_rule'   => isset($res['applied_rule']) ? (string) $res['applied_rule'] : null,
            ];
        }
        return [
            'allowed_slices' => ['catalog' => true, 'pricing' => true, 'stock' => true, 'media' => true],
            'blocked'        => [],
            'applied_rule'   => null,
        ];
    }
}
