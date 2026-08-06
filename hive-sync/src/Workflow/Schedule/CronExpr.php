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
                // Vixie compat: 7 = domenica, come il parser gemello di
                // golden-hive (jobs/cron-expr.php). Validato su 0-7 e
                // ripiegato 7→0 DOPO l'espansione.
                'dow'    => self::expand( $parts[4], 0, 6, sundayFold: true ),
            ];
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * @return int[] Sorted unique values within [$min, $max].
     */
    private static function expand( string $field, int $min, int $max, bool $sundayFold = false ): array
    {
        $effMax = $sundayFold ? 7 : $max;
        $out    = [];
        foreach ( explode( ',', $field ) as $token ) {
            $token = trim( $token );
            if ( $token === '' ) {
                throw new \InvalidArgumentException( 'empty token' );
            }

            // Step component (a/b)
            $step = 1;
            if ( str_contains( $token, '/' ) ) {
                [ $base, $stepStr ] = explode( '/', $token, 2 );
                if ( ! ctype_digit( trim( $stepStr ) ) ) {
                    throw new \InvalidArgumentException( 'bad step' );
                }
                $step = (int) $stepStr;
                if ( $step <= 0 ) throw new \InvalidArgumentException( 'bad step' );
                $token = $base;
            }

            // Range or wildcard. ctype_digit obbligatorio: senza,
            // (int)'abc' → 0 e nei campi con min 0 (minute/hour/dow) un
            // typo tipo 'mon' passava la validazione come 0 — il job
            // girava "al minuto :00" invece di segnalare l'errore.
            if ( $token === '*' ) {
                $from = $min; $to = $effMax;
            } elseif ( str_contains( $token, '-' ) ) {
                [ $a, $b ] = explode( '-', $token, 2 );
                if ( ! ctype_digit( trim( $a ) ) || ! ctype_digit( trim( $b ) ) ) {
                    throw new \InvalidArgumentException( "bad range: {$token}" );
                }
                $from = (int) $a; $to = (int) $b;
            } elseif ( ctype_digit( $token ) ) {
                $from = (int) $token; $to = $from;
            } else {
                throw new \InvalidArgumentException( "bad token: {$token}" );
            }

            if ( $from < $min || $to > $effMax || $from > $to ) {
                throw new \InvalidArgumentException( "out of range: {$from}-{$to}" );
            }

            for ( $v = $from; $v <= $to; $v += $step ) {
                $out[ ( $sundayFold && $v === 7 ) ? 0 : $v ] = true;
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
        // Strictly the NEXT minute — mai il minuto corrente. Il vecchio
        // ceil() era inclusivo quando $from cadeva esatto sul boundary
        // (:00): nextRun ritornava $from stesso, JobRunner persisteva
        // next_run_at = adesso e il job risultava subito ri-dovuto al
        // tick successivo. Stesso contratto del parser golden-hive
        // ("strict next — never match current minute").
        $ts = ( intdiv( $from, 60 ) + 1 ) * 60;
        $ceiling = $ts + 525600 * 60 * 4;

        // Semantica cron standard per dom/dow: se ENTRAMBI sono
        // ristretti (non '*'), il giorno matcha in OR — '0 0 1 * 1' =
        // "il 1° del mese O di lunedì". L'AND precedente lo faceva
        // scattare solo su un 1° che cade di lunedì (~1,7 volte/anno
        // invece di ~64).
        $domRestricted = count( $sets['dom'] ) !== 31;
        $dowRestricted = count( $sets['dow'] ) !== 7;

        $minuteSet = array_flip( $sets['minute'] );
        $hourSet   = array_flip( $sets['hour'] );
        $domSet    = array_flip( $sets['dom'] );
        $monthSet  = array_flip( $sets['month'] );
        $dowSet    = array_flip( $sets['dow'] );

        while ( $ts < $ceiling ) {
            $dt = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
            $minute = (int) $dt->format( 'i' );
            $hour   = (int) $dt->format( 'G' );
            $dom    = (int) $dt->format( 'j' );
            $month  = (int) $dt->format( 'n' );
            $dow    = (int) $dt->format( 'w' );

            $dayOk = ( $domRestricted && $dowRestricted )
                ? ( isset( $domSet[ $dom ] ) || isset( $dowSet[ $dow ] ) )
                : ( isset( $domSet[ $dom ] ) && isset( $dowSet[ $dow ] ) );

            if ( isset( $minuteSet[ $minute ] )
                && isset( $hourSet[ $hour ] )
                && isset( $monthSet[ $month ] )
                && $dayOk
            ) {
                return $ts;
            }
            $ts += 60;
        }
        return null;
    }
}
