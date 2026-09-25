<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Schedule;

/**
 * The four atomic primitives RunnerLease needs from storage. Each call
 * must be a single compare-and-set at the storage level — two processes
 * racing on the same call must never both see `true`.
 */
interface LeaseStore
{
    /** Current raw value, or null when no lease row exists. */
    public function read(): ?string;

    /** Create the row only if absent. True when this call created it. */
    public function insertIfAbsent( string $value ): bool;

    /** Swap $expected → $value. True only if the row still held $expected. */
    public function replace( string $expected, string $value ): bool;

    /** Delete the row only if it still holds $expected. */
    public function deleteIf( string $expected ): bool;

    /** Delete the row unconditionally (operator override). */
    public function clear(): void;
}
