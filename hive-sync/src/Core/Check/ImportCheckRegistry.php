<?php
declare(strict_types=1);

namespace HiveSync\Core\Check;

final class ImportCheckRegistry
{
    /** @var array<string, ImportCheck> */
    private array $checks = [];

    public function register( ImportCheck $check ): void
    {
        $this->checks[ $check->id() ] = $check;
    }

    public function get( string $id ): ?ImportCheck
    {
        return $this->checks[ $id ] ?? null;
    }

    public function has( string $id ): bool
    {
        return isset( $this->checks[ $id ] );
    }

    /** @return ImportCheck[] */
    public function all(): array
    {
        return array_values( $this->checks );
    }
}
