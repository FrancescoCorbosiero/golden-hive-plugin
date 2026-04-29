<?php
declare(strict_types=1);

namespace GH\Operations\Support;

use GH\Core\Operation\OperationResult;

/**
 * Shared utilities for v2 Operations that adapt over legacy bulk
 * action handlers (gh_apply_percent_change, gh_set_sale_percent, etc).
 *
 * The legacy contract is `true | string | WP_Error`:
 *   - true        → success, no change details
 *   - string      → human-readable error message
 *   - WP_Error    → WP standard error object
 *
 * mapResult() collapses all three into an OperationResult; getProduct()
 * standardizes the wc_get_product look-up so each Operation isn't
 * repeating the same is-WP-loaded / is-product-found checks.
 */
final class LegacyHelpers
{
    /**
     * Fetch a WC_Product or return a short error code suitable for
     * OperationResult::failed(). Returning a string keeps Operations
     * branch-free: `if (is_string($p)) return ::failed($p);`
     */
    public static function getProduct(int $productId): \WC_Product|string
    {
        if ($productId <= 0) return 'invalid_product_id';
        if (! function_exists('wc_get_product')) return 'wc_unavailable';
        $p = \wc_get_product($productId);
        if (! $p instanceof \WC_Product) return 'product_not_found';
        return $p;
    }

    /**
     * Translate a legacy result into an OperationResult.
     *
     * @param mixed $legacy
     */
    public static function mapResult(mixed $legacy, array $changes = []): OperationResult
    {
        if ($legacy === true) {
            return OperationResult::changedWith($changes);
        }
        if (is_string($legacy)) {
            return OperationResult::failed($legacy);
        }
        if (class_exists('\\WP_Error', false) && $legacy instanceof \WP_Error) {
            return OperationResult::failed($legacy->get_error_message());
        }
        return OperationResult::failed('unknown_legacy_result');
    }
}
