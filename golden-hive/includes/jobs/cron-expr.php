<?php
/**
 * Cron Expression Parser — minimal but correct 5-field cron expression support.
 *
 * Fields (in order): minute hour day-of-month month day-of-week
 *
 * Supported syntax per field:
 * - `*`         — any value
 * - `n`         — literal
 * - `a-b`       — range
 * - `a,b,c`     — list
 * - `* /n`       — step (also `a-b/n`, `* /n`)
 * - Day-of-week: 0-6 (Sunday = 0, also accepts 7 = Sunday)
 *
 * NOT supported (intentionally — keep the parser tight):
 * - Named days/months (MON, JAN, ...)
 * - `@hourly`, `@daily`, etc. (use the UI helper to generate expressions)
 * - `L`, `W`, `#` Quartz-style extensions
 *
 * The parser validates and returns an error message on failure so the UI can
 * surface it. The next-run algorithm walks forward minute by minute with smart
 * skipping; worst case capped at ~4 years of iterations.
 *
 * Timezone: all calculations use the WordPress site timezone (wp_timezone()).
 */

defined( 'ABSPATH' ) || exit;

/** Hard cap for next-run search (minutes ≈ 5 years). */
const GH_CRON_MAX_ITERATIONS = 2629800;

/**
 * Parses a 5-field cron expression into an array of matched-value sets.
 *
 * @param string $expr Cron expression.
 * @return array{minute:int[],hour:int[],dom:int[],month:int[],dow:int[]}|WP_Error
 */
function gh_cron_parse( string $expr ): array|WP_Error {
    $expr   = trim( preg_replace( '/\s+/', ' ', $expr ) );
    $fields = explode( ' ', $expr );

    if ( count( $fields ) !== 5 ) {
        return new WP_Error( 'cron_fields', 'L\'espressione cron deve avere 5 campi (minuto ora giorno mese dow).' );
    }

    $specs = [
        'minute' => [ 0, 59 ],
        'hour'   => [ 0, 23 ],
        'dom'    => [ 1, 31 ],
        'month'  => [ 1, 12 ],
        'dow'    => [ 0, 6 ],
    ];

    $parsed = [];
    $i      = 0;

    foreach ( $specs as $name => [ $min, $max ] ) {
        $raw    = $fields[ $i++ ];
        $values = gh_cron_parse_field( $raw, $min, $max, $name );

        if ( is_wp_error( $values ) ) {
            return $values;
        }

        $parsed[ $name ] = $values;
    }

    return $parsed;
}

/**
 * Parses a single cron field.
 *
 * @return int[]|WP_Error Sorted unique matched values.
 */
function gh_cron_parse_field( string $raw, int $min, int $max, string $name ): array|WP_Error {
    $raw    = trim( $raw );
    $values = [];

    if ( $raw === '' ) {
        return new WP_Error( 'cron_field_empty', "Campo '{$name}' vuoto." );
    }

    foreach ( explode( ',', $raw ) as $chunk ) {
        $chunk = trim( $chunk );

        // Step: a-b/n or */n or a/n
        $step = 1;
        if ( str_contains( $chunk, '/' ) ) {
            [ $range, $step_s ] = explode( '/', $chunk, 2 );
            if ( ! ctype_digit( $step_s ) || (int) $step_s < 1 ) {
                return new WP_Error( 'cron_step', "Step non valido in '{$name}': {$chunk}" );
            }
            $step  = (int) $step_s;
            $chunk = $range;
        }

        // Range resolution
        if ( $chunk === '*' ) {
            $from = $min;
            $to   = $max;
        } elseif ( str_contains( $chunk, '-' ) ) {
            [ $a, $b ] = explode( '-', $chunk, 2 );
            if ( ! ctype_digit( trim( $a ) ) || ! ctype_digit( trim( $b ) ) ) {
                return new WP_Error( 'cron_range', "Range non valido in '{$name}': {$chunk}" );
            }
            $from = (int) $a;
            $to   = (int) $b;
        } elseif ( ctype_digit( $chunk ) ) {
            $from = (int) $chunk;
            $to   = $from;
        } else {
            return new WP_Error( 'cron_value', "Valore non valido in '{$name}': {$chunk}" );
        }

        // Day-of-week: accept 7 as Sunday (normalize to 0)
        if ( $name === 'dow' ) {
            if ( $from === 7 ) $from = 0;
            if ( $to === 7 ) $to = 0;
        }

        if ( $from < $min || $from > $max || $to < $min || $to > $max || $from > $to ) {
            return new WP_Error( 'cron_bounds', "Range fuori bound in '{$name}' ({$min}-{$max}): {$chunk}" );
        }

        for ( $v = $from; $v <= $to; $v += $step ) {
            $values[ $v ] = true;
        }
    }

    $out = array_map( 'intval', array_keys( $values ) );
    sort( $out );
    return $out;
}

/**
 * Computes the next run timestamp matching a cron expression.
 *
 * Returns null if no match within the hard cap (unreachable for well-formed
 * expressions — the cap is a safety net, not a feature limit).
 *
 * @param string $expr Cron expression.
 * @param int    $from Unix timestamp to start search from (exclusive, minute granularity).
 * @return int|WP_Error|null
 */
function gh_cron_next_run( string $expr, int $from = 0 ): int|WP_Error|null {
    $parsed = gh_cron_parse( $expr );
    if ( is_wp_error( $parsed ) ) return $parsed;

    $tz   = wp_timezone();
    $from = $from ?: time();

    // Round up to the next minute boundary (strict "next" — never match current minute).
    $dt = ( new DateTimeImmutable( '@' . $from ) )->setTimezone( $tz );
    $dt = $dt->setTime( (int) $dt->format( 'H' ), (int) $dt->format( 'i' ), 0 )
             ->modify( '+1 minute' );

    $minutes = array_flip( $parsed['minute'] );
    $hours   = array_flip( $parsed['hour'] );
    $doms    = array_flip( $parsed['dom'] );
    $months  = array_flip( $parsed['month'] );
    $dows    = array_flip( $parsed['dow'] );

    // Cron semantics: if both dom and dow are restricted (not '*'), match is OR.
    $dom_restricted = count( $parsed['dom'] ) !== 31;
    $dow_restricted = count( $parsed['dow'] ) !== 7;

    for ( $i = 0; $i < GH_CRON_MAX_ITERATIONS; $i++ ) {
        $m   = (int) $dt->format( 'n' );
        $d   = (int) $dt->format( 'j' );
        $h   = (int) $dt->format( 'G' );
        $min = (int) $dt->format( 'i' );
        $w   = (int) $dt->format( 'w' );

        if ( ! isset( $months[ $m ] ) ) {
            // Jump to 1st of next matching month at 00:00.
            $dt = $dt->modify( 'first day of next month' )->setTime( 0, 0, 0 );
            continue;
        }

        $day_ok = $dom_restricted && $dow_restricted
            ? ( isset( $doms[ $d ] ) || isset( $dows[ $w ] ) )
            : ( ( ! $dom_restricted || isset( $doms[ $d ] ) ) && ( ! $dow_restricted || isset( $dows[ $w ] ) ) );

        if ( ! $day_ok ) {
            $dt = $dt->modify( '+1 day' )->setTime( 0, 0, 0 );
            continue;
        }

        if ( ! isset( $hours[ $h ] ) ) {
            // Zero the minute and advance to the next hour boundary (handles day rollover).
            $dt = $dt->setTime( $h, 0, 0 )->modify( '+1 hour' );
            continue;
        }

        if ( ! isset( $minutes[ $min ] ) ) {
            $dt = $dt->modify( '+1 minute' );
            continue;
        }

        return $dt->getTimestamp();
    }

    return null;
}

/**
 * Builds a cron expression from a simple "every N unit" specification.
 *
 * Supported units: minute, hour, day, week.
 * This is the bridge between the minimal UI and the powerful cron-expression
 * field — the UI generates text into the same input the dev can then edit.
 *
 * @param int    $every Positive integer.
 * @param string $unit  One of: minute, hour, day, week.
 * @return string|WP_Error
 */
function gh_cron_from_simple( int $every, string $unit ): string|WP_Error {
    if ( $every < 1 ) {
        return new WP_Error( 'cron_simple', 'Intervallo deve essere >= 1.' );
    }

    return match ( $unit ) {
        'minute' => $every === 1 ? '* * * * *' : "*/{$every} * * * *",
        'hour'   => $every === 1 ? '0 * * * *' : "0 */{$every} * * *",
        'day'    => $every === 1 ? '0 0 * * *' : "0 0 */{$every} * *",
        'week'   => '0 0 * * 0', // "every week" = weekly Sunday midnight (ignores $every>1)
        default  => new WP_Error( 'cron_unit', "Unità non valida: {$unit}" ),
    };
}

/**
 * Human-readable summary of a cron expression.
 *
 * Best-effort — recognizes common simple patterns and falls back to showing
 * the raw expression.
 */
function gh_cron_describe( string $expr ): string {
    $expr = trim( preg_replace( '/\s+/', ' ', $expr ) );

    $common = [
        '* * * * *'     => 'Ogni minuto',
        '*/5 * * * *'   => 'Ogni 5 minuti',
        '*/10 * * * *'  => 'Ogni 10 minuti',
        '*/15 * * * *'  => 'Ogni 15 minuti',
        '*/30 * * * *'  => 'Ogni 30 minuti',
        '0 * * * *'     => 'Ogni ora',
        '0 */2 * * *'   => 'Ogni 2 ore',
        '0 */6 * * *'   => 'Ogni 6 ore',
        '0 */12 * * *'  => 'Ogni 12 ore',
        '0 0 * * *'     => 'Ogni giorno a mezzanotte',
        '0 3 * * *'     => 'Ogni giorno alle 03:00',
        '0 0 * * 0'     => 'Ogni domenica a mezzanotte',
        '0 0 1 * *'     => 'Il 1° di ogni mese',
    ];

    return $common[ $expr ] ?? $expr;
}
