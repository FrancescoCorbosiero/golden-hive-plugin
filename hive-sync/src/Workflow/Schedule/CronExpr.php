<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Schedule;

/**
 * Minimal 5-field cron expression parser:
 *   minute  hour  day-of-month  month  day-of-week
 *
 * Each field supports:
 *   *           any value
 *   N           literal
 *   N-M         inclusive range

 *   star/N      step  (slash-N after a base; "*" base = every Nth)
 *   N-M/S       range with step
 *   a,b,c       union (each token is one of the above)
 *
 * No support for named months/weekdays, no support for predefined
 * shortcuts (@daily etc.). Keeping the surface small → easier to audit
 * for off-by-ones than reinventing every cron flavor.
 */
final class CronExpr
{
    /**
     * Parse + validate. Returns the expanded set per field, or null on
     * malformed input.
     *
     * @return array{minute:int[], hour:int[], dom:int[], month:int[], dow:int[]}|null
     */
    public static function parse( string $expr ): ?array
    {
        $parts = preg_split( '/\s+/', trim( $expr ) );
        if ( ! is_array( $parts ) || count( $parts ) !== 5 ) return null;

        try {
            return [
                'minute' => self::expand( $parts[0], 0, 59 ),
                'hour'   => self::expand( $parts[1], 0, 23 ),
                'dom'    => self::expand( $parts[2], 1, 31 ),
                'month'  => self::expand( $parts[3], 1, 12 ),
                'dow'    => self::expand( $parts[4], 0, 6 ),
            ];
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * @return int[] Sorted unique values within [$min, $max].
     */
    private static function expand( string $field, int $min, int $max ): array
    {
        $out = [];
        foreach ( explode( ',', $field ) as $token ) {
            $token = trim( $token );
            if ( $token === '' ) {
                throw new \InvalidArgumentException( 'empty token' );
            }

            // Step component (a/b)
            $step = 1;
            if ( str_contains( $token, '/' ) ) {
                [ $base, $stepStr ] = explode( '/', $token, 2 );
                $step = (int) $stepStr;
                if ( $step <= 0 ) throw new \InvalidArgumentException( 'bad step' );
                $token = $base;
            }

            // Range or wildcard
            if ( $token === '*' ) {
                $from = $min; $to = $max;
            } elseif ( str_contains( $token, '-' ) ) {
                [ $a, $b ] = explode( '-', $token, 2 );
                $from = (int) $a; $to = (int) $b;
            } else {
                $from = (int) $token; $to = $from;
            }

            if ( $from < $min || $to > $max || $from > $to ) {
                throw new \InvalidArgumentException( "out of range: {$from}-{$to}" );
            }

            for ( $v = $from; $v <= $to; $v += $step ) {
                $out[ $v ] = true;
            }
        }
        $keys = array_keys( $out );
        sort( $keys );
        return $keys;
    }

    /**
     * Walk forward from $from (a unix timestamp) one minute at a time
     * until we find the next moment matching all five fields.
     *
     * Bounded by a 4-year ceiling (525600 * 4 minutes) so a malformed
     * expression that nobody can satisfy returns null instead of
     * spinning forever.
     */
    public static function nextRun( string $expr, int $from, ?\DateTimeZone $tz = null ): ?int
    {
        $sets = self::parse( $expr );
        if ( $sets === null ) return null;

        $tz = $tz ?? new \DateTimeZone( 'UTC' );
        // Round up to the next whole minute.
        $ts = (int) ( ceil( $from / 60 ) * 60 );
        $ceiling = $ts + 525600 * 60 * 4;

        while ( $ts < $ceiling ) {
            $dt = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
            $minute = (int) $dt->format( 'i' );
            $hour   = (int) $dt->format( 'G' );
            $dom    = (int) $dt->format( 'j' );
            $month  = (int) $dt->format( 'n' );
            $dow    = (int) $dt->format( 'w' );

            if ( in_array( $minute, $sets['minute'], true )
                && in_array( $hour, $sets['hour'], true )
                && in_array( $dom, $sets['dom'], true )
                && in_array( $month, $sets['month'], true )
                && in_array( $dow, $sets['dow'], true )
            ) {
                return $ts;
            }
            $ts += 60;
        }
        return null;
    }
}
