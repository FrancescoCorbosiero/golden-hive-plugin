<?php
declare(strict_types=1);

namespace HiveSync\Sources;

/**
 * Per-item markup resolver.
 *
 * Source-configs ship with a flat `markup_percent` (default 0) plus an
 * optional `markup_rules` list — each rule a `{field, operator, value,
 * percent}` row. For each FeedItem we walk the rules top-to-bottom;
 * the first match wins and its `percent` is applied. No match falls
 * back to the flat `markup_percent`.
 *
 * Why match feed fields (not Woo taxonomies):
 * - The feed is the source of truth for a 10k catalog; the Woo
 *   taxonomy is derived from the feed via the mapping/transform.
 * - Querying Woo taxonomy for every item in a 10k fetch would be
 *   N×lookup (slow) and would also break for NEW products that don't
 *   exist in Woo yet at fetch time.
 * - Idempotent: same feed input → same percent → same Woo price.
 *
 * Rule shape (validated in normalize()):
 *   field    string   path into FeedItem.data; supports dot notation
 *                     for nested values (e.g. "meta.brand")
 *   operator enum     'equals' | 'not_equals' | 'in' | 'not_in'
 *                     | 'contains' | 'starts_with'
 *   value    scalar|array  string for scalar ops, array for in/not_in
 *   percent  number   markup percentage (e.g. 30 = +30%)
 */
final class MarkupResolver
{
    /**
     * Resolve the multiplier (1 + pct/100) to apply to a single item.
     *
     * @param array<string, mixed> $itemData     FeedItem.data slot
     * @param array<int, array>    $rules        normalized markup_rules
     * @param float                $fallbackPct  default percent when no rule matches
     */
    public static function multiplierFor(array $itemData, array $rules, float $fallbackPct): float
    {
        foreach ($rules as $rule) {
            if (self::matches($itemData, $rule)) {
                return 1.0 + ((float) $rule['percent'] / 100.0);
            }
        }
        return 1.0 + ($fallbackPct / 100.0);
    }

    /**
     * Coerce raw config input into a stable list of well-typed rules.
     * Drops malformed rows silently — better to ship a partial rule
     * set than to fail the whole fetch on one typo.
     *
     * @param mixed $raw
     * @return array<int, array{field:string, operator:string, value:mixed, percent:float}>
     */
    public static function normalize(mixed $raw): array
    {
        if (! is_array($raw)) return [];
        $out = [];
        $allowedOps = ['equals', 'not_equals', 'in', 'not_in', 'contains', 'starts_with'];
        foreach ($raw as $row) {
            if (! is_array($row)) continue;
            $field    = trim((string) ($row['field']    ?? ''));
            $operator = (string) ($row['operator'] ?? 'equals');
            if ($field === '' || ! in_array($operator, $allowedOps, true)) continue;
            // `percent` of 0 is technically valid (no markup) but
            // saves the operator zero work — we still keep the rule
            // because it might be used to override an inherited
            // fallback to "no markup for this category".
            if (! isset($row['percent']) || ! is_numeric($row['percent'])) continue;
            $value = $row['value'] ?? '';
            // `in` / `not_in` need an array. Operators tolerate either
            // a real PHP array or a comma-separated string from the UI.
            if (in_array($operator, ['in', 'not_in'], true)) {
                if (is_string($value)) {
                    $value = array_values(array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== ''));
                } elseif (! is_array($value)) {
                    $value = [];
                }
            } else {
                $value = is_scalar($value) ? (string) $value : '';
            }
            $out[] = [
                'field'    => $field,
                'operator' => $operator,
                'value'    => $value,
                'percent'  => (float) $row['percent'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @param array{field:string, operator:string, value:mixed} $rule
     */
    private static function matches(array $data, array $rule): bool
    {
        $actual = self::dotGet($data, $rule['field']);
        if ($actual === null) return false;
        $actualStr = is_scalar($actual) ? (string) $actual : '';

        return match ($rule['operator']) {
            'equals'      => $actualStr === (string) $rule['value'],
            'not_equals'  => $actualStr !== (string) $rule['value'],
            'in'          => is_array($rule['value']) && in_array($actualStr, array_map('strval', $rule['value']), true),
            'not_in'      => is_array($rule['value']) && ! in_array($actualStr, array_map('strval', $rule['value']), true),
            'contains'    => $actualStr !== '' && str_contains($actualStr, (string) $rule['value']),
            'starts_with' => $actualStr !== '' && str_starts_with($actualStr, (string) $rule['value']),
            default       => false,
        };
    }

    /**
     * Dot-path lookup so rules can target nested fields like
     * "meta.brand" without each Source needing custom resolution.
     */
    private static function dotGet(array $data, string $path): mixed
    {
        if (! str_contains($path, '.')) {
            return $data[$path] ?? null;
        }
        $cur = $data;
        foreach (explode('.', $path) as $seg) {
            if (! is_array($cur) || ! array_key_exists($seg, $cur)) return null;
            $cur = $cur[$seg];
        }
        return $cur;
    }
}
