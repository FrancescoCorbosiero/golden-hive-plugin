<?php
declare(strict_types=1);

namespace HiveSync\Sources;

/**
 * Promote mapped `pa_*` keys on a FeedItem.data draft into the
 * canonical $data['attributes'] block consumed by the host bridge.
 *
 * The mapping layer overlays user-declared targets onto the draft
 * AFTER the source-specific transforms have run. So a user who maps
 * `pa_model => model_name` ends up with `data['pa_model'] = 'Air Max'`
 * — but the bridge only inspects `data['attributes']` when wiring
 * Woo product attributes. This helper closes that gap by walking the
 * draft once after mapping and merging every `pa_*` scalar/list into
 * the attributes block as a visible non-variation attribute (unless
 * the transform already declared it as a variation, in which case
 * we union the options without flipping the flag).
 *
 * `pa_taglia` from the GS / SF transforms keeps `variation: true` —
 * the merge here is option-only, not metadata-overwriting.
 */
final class AttributeMerger
{
    /**
     * Mutate $data in place. Idempotent: a re-run with the same input
     * produces the same output.
     *
     * @param array<string, mixed> $data
     */
    public static function promoteFromDraft(array &$data): void
    {
        $attrs = (array) ($data['attributes'] ?? []);
        $changed = false;

        foreach ($data as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'pa_')) continue;

            $options = self::asOptions($value);
            if (! $options) continue;

            if (isset($attrs[$key]) && is_array($attrs[$key])) {
                // Existing slot (likely set by transformToWoo for
                // pa_taglia / pa_brand). Union the options, keep the
                // variation/visible flags intact.
                $existing = (array) ($attrs[$key]['options'] ?? []);
                $merged = array_values(array_unique(array_merge($existing, $options)));
                if ($merged !== $existing) {
                    $attrs[$key]['options'] = $merged;
                    $changed = true;
                }
            } else {
                $attrs[$key] = [
                    'options'   => $options,
                    'visible'   => true,
                    'variation' => false,
                ];
                $changed = true;
            }
        }

        if ($changed) {
            $data['attributes'] = $attrs;
        }
    }

    /**
     * Coerce a draft value into a clean list of trimmed non-empty option
     * strings. Accepts string (single), pipe-joined string, or array.
     *
     * @return string[]
     */
    private static function asOptions(mixed $value): array
    {
        if (is_string($value)) {
            $value = str_contains($value, '|') ? explode('|', $value) : [$value];
        }
        if (! is_array($value)) return [];

        $out = [];
        foreach ($value as $v) {
            if (! is_scalar($v)) continue;
            $trimmed = trim((string) $v);
            if ($trimmed !== '') $out[] = $trimmed;
        }
        return array_values(array_unique($out));
    }
}
