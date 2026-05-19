<?php
declare(strict_types=1);

namespace HiveSync\Core\Source;

final class MaterializeResult
{
    public function __construct(
        public readonly ?int $productId,
        public readonly string $action,        // 'created' | 'updated' | 'recreated' | 'skipped' | 'failed'
        public readonly array $details = [],
        public readonly ?string $error = null,
        public readonly array $blockedSlices = [], // populated when conflict engine vetoes a slice
    ) {}

    public function isSuccess(): bool
    {
        return $this->productId !== null && $this->error === null;
    }

    public static function created(int $productId, array $details = []): self
    {
        return new self(productId: $productId, action: 'created', details: $details);
    }

    public static function updated(int $productId, array $details = []): self
    {
        return new self(productId: $productId, action: 'updated', details: $details);
    }

    /**
     * Force-recreate path: existing product had its variations +
     * pa_* terms + _product_attributes wiped, then re-written from
     * the feed. The product ID is preserved (so historical orders
     * + permalinks stay valid); only the WC-derived state was
     * regenerated. Surfaced separately from 'updated' so the
     * Storico tab can distinguish a destructive rewrite from a
     * regular delta-update.
     */
    public static function recreated(int $productId, array $details = []): self
    {
        return new self(productId: $productId, action: 'recreated', details: $details);
    }

    public static function skipped(?int $productId, string $reason): self
    {
        return new self(productId: $productId, action: 'skipped', details: ['reason' => $reason]);
    }

    public static function failed(string $error, ?int $productId = null): self
    {
        return new self(productId: $productId, action: 'failed', error: $error);
    }
}
