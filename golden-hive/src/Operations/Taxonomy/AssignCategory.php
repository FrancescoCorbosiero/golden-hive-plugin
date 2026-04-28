<?php
declare(strict_types=1);

namespace GH\Operations\Taxonomy;

use GH\Core\Operation\Operation;
use GH\Core\Operation\OperationContext;
use GH\Core\Operation\OperationResult;
use GH\Operations\Support\LegacyHelpers;

/**
 * Add (does not replace) category terms to the product. v2 port of
 * legacy 'assign_categories' bulk action.
 *
 * params:
 *   category_ids  int[]  required, non-empty
 */
final class AssignCategory implements Operation
{
    public const ID = 'taxonomy.assign_category';

    public function id(): string { return self::ID; }
    public function label(): string { return 'Aggiungi categoria (product_cat)'; }

    public function paramsSchema(): array
    {
        return [
            'category_ids' => [
                'type'     => 'int_list',
                'label'    => 'Category IDs (CSV o array)',
                'required' => true,
            ],
        ];
    }

    public function appliesTo(): array
    {
        return ['simple', 'variable', 'grouped', 'external'];
    }

    public function apply(int $productId, array $params, OperationContext $ctx): OperationResult
    {
        $ids = self::normalizeIds($params['category_ids'] ?? []);
        if (empty($ids)) {
            return OperationResult::failed('invalid_category_ids');
        }

        if ($ctx->isDryRun()) {
            return OperationResult::changedWith([
                'category_ids' => $ids,
                'dry_run'      => true,
            ]);
        }

        if (! function_exists('rp_cm_assign_product_categories')) {
            return OperationResult::failed('legacy taxonomy module unavailable');
        }
        if ($productId <= 0) {
            return OperationResult::failed('invalid_product_id');
        }

        return LegacyHelpers::mapResult(
            \rp_cm_assign_product_categories($productId, $ids),
            ['category_ids' => $ids, 'taxonomy' => 'product_cat'],
        );
    }

    /** @return int[] */
    private static function normalizeIds(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : explode(',', $raw);
        }
        if (! is_array($raw)) return [];
        $out = [];
        foreach ($raw as $v) {
            $i = (int) $v;
            if ($i > 0) $out[] = $i;
        }
        return array_values(array_unique($out));
    }
}
