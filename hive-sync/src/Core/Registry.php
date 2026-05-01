<?php
declare(strict_types=1);

namespace HiveSync\Core;

/**
 * Generic slug→object registry. Used for sources, operations, checks —
 * any plugin component that registers itself once at boot and is later
 * resolved by id.
 *
 * @template T of object
 */
final class Registry {

    /** @var array<string, object> */
    private array $items = [];

    public function __construct(
        private readonly string $kind,
    ) {}

    public function register( string $id, object $item ): void {
        if ( isset( $this->items[ $id ] ) ) {
            throw new \LogicException( "Hive Sync: duplicate {$this->kind} id '{$id}'" );
        }
        $this->items[ $id ] = $item;
    }

    public function get( string $id ): ?object {
        return $this->items[ $id ] ?? null;
    }

    public function has( string $id ): bool {
        return isset( $this->items[ $id ] );
    }

    /** @return array<string, object> */
    public function all(): array {
        return $this->items;
    }
}
