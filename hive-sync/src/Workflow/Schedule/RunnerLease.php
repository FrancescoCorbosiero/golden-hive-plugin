<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Schedule;

/**
 * The one mutex every scheduled-job execution goes through: the cron
 * heartbeat, "Esegui ora", a job's "Run" button. Whoever holds it is
 * the only process allowed to advance a job's run (cursor, run_state,
 * next_run_at). Everybody else backs off or leaves a request on the
 * job row (see JobRunner::requestRun / requestStop).
 *
 * Value = "<token>|<expires unix ts>". Acquire is an atomic insert; a
 * row past its expiry is taken over by an atomic compare-and-swap on
 * the exact stale value, so two processes can't both take over the
 * same dead lease. Renew is the same CAS against the value we hold: it
 * fails only if the lease expired AND somebody else took it — the holder
 * then stops instead of racing the new owner.
 *
 * The TTL is a crash-recovery horizon, not a runtime budget: a process
 * that dies without releasing (fatal, OOM, container restart) blocks
 * scheduled work for at most one TTL. It must comfortably exceed the
 * longest single slice (fetch + diff + 25s loop + one slow item), and
 * the holder renews it before every slice.
 */
final class RunnerLease
{
    public const DEFAULT_TTL = 900; // 15 min

    /** Raw value this instance currently holds, null when not holding. */
    private ?string $held = null;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly LeaseStore $store,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public static function forSite(): self
    {
        return new self( new OptionsLeaseStore() );
    }

    public function acquire( int $ttl = self::DEFAULT_TTL ): bool
    {
        if ( $this->held !== null ) {
            return $this->renew( $ttl );
        }

        $now   = ( $this->clock )();
        $value = self::encode( bin2hex( random_bytes( 8 ) ), $now + $ttl );

        if ( $this->store->insertIfAbsent( $value ) ) {
            $this->held = $value;
            return true;
        }

        $current = $this->store->read();
        if ( $current === null ) {
            // Released between our insert and our read: one more try.
            if ( $this->store->insertIfAbsent( $value ) ) {
                $this->held = $value;
                return true;
            }
            return false;
        }

        if ( self::decode( $current )['expires'] > $now ) {
            return false; // a live process holds it
        }

        // Dead holder. Take over — but only if nobody beat us to it.
        if ( $this->store->replace( $current, $value ) ) {
            $this->held = $value;
            return true;
        }
        return false;
    }

    public function renew( int $ttl = self::DEFAULT_TTL ): bool
    {
        if ( $this->held === null ) return false;

        $value = self::encode( self::decode( $this->held )['token'], ( $this->clock )() + $ttl );
        if ( $value === $this->held ) {
            // Same second as the last write. Nothing to extend — and an
            // UPDATE to an identical value reports 0 affected rows, which
            // would read as "lost".
            return true;
        }
        if ( $this->store->replace( $this->held, $value ) ) {
            $this->held = $value;
            return true;
        }
        // Expired and taken over by another process: it owns the jobs now.
        $this->held = null;
        return false;
    }

    public function release(): void
    {
        if ( $this->held === null ) return;
        $this->store->deleteIf( $this->held );
        $this->held = null;
    }

    public function isHeld(): bool
    {
        return $this->held !== null;
    }

    /**
     * Who holds the lease right now, for the UI / diagnostics.
     *
     * @return array{token: string, expires: int, expired: bool}|null
     */
    public function inspect(): ?array
    {
        $current = $this->store->read();
        if ( $current === null ) return null;
        $d = self::decode( $current );
        return $d + [ 'expired' => $d['expires'] <= ( $this->clock )() ];
    }

    /**
     * Operator override ("Sblocca" in Strumenti). Safe even if a process
     * is mid-slice: its next renew() fails the CAS and it stops.
     */
    public function forceRelease(): void
    {
        $this->store->clear();
        $this->held = null;
    }

    private static function encode( string $token, int $expires ): string
    {
        return $token . '|' . $expires;
    }

    /** @return array{token: string, expires: int} */
    private static function decode( string $value ): array
    {
        $parts = explode( '|', $value, 2 );
        // A malformed value decodes as already expired → reclaimable.
        return [
            'token'   => (string) $parts[0],
            'expires' => isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : 0,
        ];
    }
}
