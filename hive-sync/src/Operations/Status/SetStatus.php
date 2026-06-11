<?php
declare(strict_types=1);

namespace HiveSync\Operations\Status;

use HiveSync\Core\Operation\Operation;
use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Operation\OperationResult;

/**
 * Set the WordPress post_status on a product. Pure WP — no Hive Commerce
 * coupling, runs equally well against a vanilla Woo install.
 */
final class SetStatus implements Operation
{
    public const ID = 'status.set';

    private const ALLOWED = ['publish', 'draft', 'private', 'pending'];

    public function id(): string { return self::ID; }
    public function label(): string { return 'Imposta stato'; }

    public function paramsSchema(): array
    {
        return [
            'status' => [
                'type'     => 'enum',
                'label'    => 'Stato',
                'required' => true,
                'options'  => self::ALLOWED,
            ],
        ];
    }

    public function appliesTo(): array
    {
        return ['simple', 'variable', 'grouped', 'external'];
    }

    public function apply(int $productId, array $params, OperationContext $ctx): OperationResult
    {
        $status = $params['status'] ?? null;
        if (! is_string($status) || ! in_array($status, self::ALLOWED, true)) {
            return OperationResult::failed('invalid_status');
        }
        if ($productId <= 0) {
            return OperationResult::failed('invalid_product_id');
        }

        if ($ctx->isDryRun()) {
            return OperationResult::changedWith(['status' => $status, 'dry_run' => true]);
        }

        if (! function_exists('wp_update_post')) {
            return OperationResult::failed('wp_update_post unavailable');
        }

        $r = \wp_update_post(['ID' => $productId, 'post_status' => $status], true);
        if (function_exists('is_wp_error') && \is_wp_error($r)) {
            return OperationResult::failed($r->get_error_message());
        }
        if ((int) $r === 0) {
            return OperationResult::failed('wp_update_post returned 0');
        }

        return OperationResult::changedWith(['status' => $status]);
    }
}
