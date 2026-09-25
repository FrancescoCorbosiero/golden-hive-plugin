<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Schedule;

/**
 * Slot arithmetic for scheduled jobs: which cron occurrence comes next,
 * and what happens to the occurrences that fall while a run is still
 * going. Pure — the timezone is injected, so tests pin it.
 *
 * Cron expressions are read in the SITE timezone (Impostazioni →
 * Generali), not UTC. The editor tells the operator "Ogni giorno alle
 * 00:00": that has to mean midnight on the shop's clock. Evaluated in
 * UTC it fired at 02:00 Rome time in summer and 01:00 in winter, and
 * the history — which printed raw UTC — looked right only by accident.
 * Timestamps stay UTC in the DB; only the cron reading is local.
 *
 * The slot model:
 *
 *   - Every run starts ON a slot (± one heartbeat). next_run_at always
 *     names the next slot to start a run on, also while a run is going.
 *   - planAfter(): when a run starts for slot S, the next slot is the
 *     one after S — anchored on the grid, not on "now", so a run that
 *     began a minute late doesn't shift anything.
 *   - afterRun(): slots that came due while the run was still going are
 *     skipped, never queued. A sync that overran its interval must not
 *     restart back-to-back off-grid; it resumes on the next slot and
 *     the skip is COUNTED, so the UI can say why the history has a gap
 *     instead of leaving the operator to reverse-engineer it.
 */
final class JobSchedule
{
    /** Cap on how many missed slots afterRun() walks (and reports). */
    public const MAX_COUNTED_SKIPS = 500;

    public function __construct(
        private readonly \DateTimeZone $tz,
    ) {}

    /**
     * The WordPress site timezone (falls back to UTC outside WP).
     */
    public static function siteTimezone(): \DateTimeZone
    {
        return function_exists( 'wp_timezone' ) ? \wp_timezone() : new \DateTimeZone( 'UTC' );
    }

    public static function forSite(): self
    {
        return new self( self::siteTimezone() );
    }

    public function timezone(): \DateTimeZone
    {
        return $this->tz;
    }

    /**
     * Next slot strictly after $from, or null for an empty/invalid cron.
     */
    public function nextAfter( ?string $cron, int $from ): ?int
    {
        $cron = trim( (string) $cron );
        if ( $cron === '' ) return null;
        return CronExpr::nextRun( $cron, $from, $this->tz );
    }

    /**
     * The slot to plan when a run starts for $servedSlot (null = the run
     * is not serving a slot: manual trigger, or the job had no plan yet).
     *
     * Anchored on the served slot. When even that is already in the past
     * — the run started more than one interval late (site down, cron dead
     * for hours) — the missed slots are folded into this run and the plan
     * jumps to the first slot after $now: one catch-up run, not a burst.
     */
    public function planAfter( ?string $cron, ?int $servedSlot, int $now ): ?int
    {
        $next = $this->nextAfter( $cron, $servedSlot ?? $now );
        if ( $next !== null && $next <= $now ) {
            $next = $this->nextAfter( $cron, $now );
        }
        return $next;
    }

    /**
     * Where the schedule stands when a run finishes at $now.
     *
     * $planned is the slot planned at run start (the job's next_run_at).
     * If it is still ahead, nothing was missed. If it passed while the
     * run was going, it and every later slot up to $now are skipped and
     * counted, and the next run goes on the first slot after $now.
     *
     * @return array{next: ?int, skipped: int}
     */
    public function afterRun( ?string $cron, ?int $planned, int $now ): array
    {
        if ( $planned === null ) {
            return [ 'next' => $this->nextAfter( $cron, $now ), 'skipped' => 0 ];
        }
        if ( $planned > $now ) {
            return [ 'next' => $planned, 'skipped' => 0 ];
        }

        $skipped = 0;
        $slot    = $planned;
        while ( $slot !== null && $slot <= $now && $skipped < self::MAX_COUNTED_SKIPS ) {
            $skipped++;
            $slot = $this->nextAfter( $cron, $slot );
        }
        if ( $slot !== null && $slot <= $now ) {
            // Hit the counting cap on a dense cron: stop counting, but
            // still land on a future slot.
            $slot = $this->nextAfter( $cron, $now );
        }
        return [ 'next' => $slot, 'skipped' => $skipped ];
    }

    /**
     * 'Y-m-d H:i:s' UTC (the DB format) → unix timestamp, or null.
     */
    public static function parseUtc( mixed $value ): ?int
    {
        if ( ! is_string( $value ) || $value === '' ) return null;
        $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
        return $dt === false ? null : $dt->getTimestamp();
    }

    /**
     * unix timestamp → 'Y-m-d H:i:s' UTC (the DB format), or null.
     */
    public static function formatUtc( ?int $ts ): ?string
    {
        return $ts === null ? null : gmdate( 'Y-m-d H:i:s', $ts );
    }
}
