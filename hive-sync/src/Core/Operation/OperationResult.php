<?php
declare(strict_types=1);

namespace HiveSync\Core\Operation;

final class OperationResult
{
    public function __construct(
        public readonly bool $changed,
        public readonly array $changes = [],
        public readonly ?string $error = null,
        public readonly array $warnings = [],
    ) {}

    public static function noop(): self
    {
        return new self(changed: false);
    }

    public static function changedWith(array $changes): self
    {
        return new self(changed: true, changes: $changes);
    }

    public static function failed(string $error): self
    {
        return new self(changed: false, error: $error);
    }

    public function isSuccess(): bool
    {
        return $this->error === null;
    }
}
