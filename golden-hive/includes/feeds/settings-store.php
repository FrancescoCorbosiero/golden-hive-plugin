<?php
/**
 * Unified settings IO contract for feed/service config (KicksDB, GS, SF, …).
 *
 * Goals:
 *   1. Per-field result: every save reports updated|preserved|cleared|rejected
 *      for each field. The user (and the JS toast) sees exactly what changed.
 *   2. Verify-after-write: read the option back after update_option() and
 *      compute the response from the actual stored value, not from what we
 *      think we wrote. If WP filters or another plugin mangles the value,
 *      the response surfaces the mismatch.
 *   3. No silent preservation of placeholder bullets. Bullet-prefix on a
 *      secret field = explicitly REJECTED, not silently preserved. The
 *      previous "preserve when bullets" behaviour hid bugs because the user
 *      had no way to tell whether the field actually got updated.
 *
 * Supported services and the underlying option keys:
 *   - 'kicksdb'        → wp_options['gh_kicksdb_settings']         (nested shape)
 *   - 'goldensneakers' → wp_options['gh_feed_settings_goldensneakers'] (flat)
 *   - 'stockfirmati'   → wp_options['gh_feed_settings_stockfirmati']   (flat)
 *
 * Public API:
 *   gh_settings_save(string $service, array $payload): array
 *   gh_settings_get(string $service, bool $redact = true): array
 *   gh_settings_dump_debug(string $service): array     // WP_DEBUG only
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the per-service field schema. Each field declares:
 *   - type:         'text' | 'url' | 'enum' | 'secret' | 'int' | 'float' | 'bool'
 *   - secret:       true  → never sent back in plaintext, redacted to last4
 *   - allow_empty:  bool  → if false, an explicit empty value returns 'rejected'
 *   - max:          int   → byte cap (defensive)
 *   - options:      array → for enum
 *   - min/max_num:  for int/float clamping
 *   - default:      scalar fallback
 *   - path:         array → option-key path (for nested shapes like kicksdb.pricing.margin_pct)
 *                   defaults to [field_name]
 *
 * Schemas are flat in the API surface (one level of field names) — nesting in
 * the actual option is handled via `path`. This keeps the JS contract simple:
 * `{ field_name: value }` regardless of how the option is shaped on disk.
 */
function gh_settings_schema(): array {
    return [

        'kicksdb' => [
            'option_key' => 'gh_kicksdb_settings',
            'autoload'   => false,
            'fields'     => [
                'api_key'       => [ 'type' => 'secret', 'allow_empty' => false, 'max' => 4096, 'path' => [ 'api_key' ] ],
                'base_url'      => [ 'type' => 'url',    'max' => 4096,
                                     'default' => defined( 'GH_KICKSDB_BASE_URL_DEFAULT' ) ? GH_KICKSDB_BASE_URL_DEFAULT : 'https://api.kicks.dev/v3',
                                     'path' => [ 'base_url' ] ],
                'market'        => [ 'type' => 'text',   'max' => 3,    'default' => 'IT', 'path' => [ 'market' ] ],
                'concurrency'   => [ 'type' => 'int',    'min_num' => 1,'max_num' => 16, 'default' => 8,    'path' => [ 'concurrency' ] ],
                'cache_ttl'     => [ 'type' => 'int',    'min_num' => 60,'max_num' => 7 * DAY_IN_SECONDS, 'default' => DAY_IN_SECONDS, 'path' => [ 'cache_ttl' ] ],
                'margin_pct'    => [ 'type' => 'float',  'min_num' => -99.0,'max_num' => 1000.0, 'default' => 20.0, 'path' => [ 'pricing', 'margin_pct' ] ],
                'floor_price'   => [ 'type' => 'float',  'min_num' => 0.0,  'default' => 0.0,    'path' => [ 'pricing', 'floor_price' ] ],
                'rounding_mode' => [ 'type' => 'enum',   'options' => [ 'ceil', 'round', 'floor' ], 'default' => 'ceil', 'path' => [ 'pricing', 'rounding_mode' ] ],
                'rounding_step' => [ 'type' => 'float',  'min_num' => 0.01, 'default' => 1.0,    'path' => [ 'pricing', 'rounding_step' ] ],
                'currency'      => [ 'type' => 'text',   'max' => 3,        'default' => 'EUR',  'path' => [ 'pricing', 'currency' ] ],
            ],
        ],

        'goldensneakers' => [
            'option_key' => 'gh_feed_settings_goldensneakers',
            'autoload'   => false,
            'fields'     => [
                'url'    => [ 'type' => 'url',    'allow_empty' => false, 'max' => 4096 ],
                'token'  => [ 'type' => 'secret', 'allow_empty' => false, 'max' => 8192 ],
                'cookie' => [ 'type' => 'secret', 'max' => 16384 ],
                'format' => [ 'type' => 'enum',   'options' => [ 'hierarchical', 'flat' ], 'max' => 16, 'default' => 'hierarchical' ],
            ],
        ],

        'stockfirmati' => [
            'option_key' => 'gh_feed_settings_stockfirmati',
            'autoload'   => false,
            'fields'     => [
                'url' => [ 'type' => 'url', 'max' => 4096 ],
            ],
        ],
    ];
}

function gh_settings_service_exists( string $service ): bool {
    return array_key_exists( $service, gh_settings_schema() );
}

function gh_settings_service_def( string $service ): ?array {
    $all = gh_settings_schema();
    return $all[ $service ] ?? null;
}

/**
 * Reads the raw stored option (no redaction, no defaults). Internal use only.
 * Use gh_settings_get() for everything that crosses the AJAX boundary.
 */
function gh_settings_read_raw( string $service ): array {
    $def = gh_settings_service_def( $service );
    if ( ! $def ) return [];
    $v = get_option( $def['option_key'], [] );
    return is_array( $v ) ? $v : [];
}

/**
 * Returns the current settings for a service as a FLAT field map keyed by the
 * names declared in the schema. Secrets are redacted by default to a last-4
 * fingerprint — UIs render this as "Salvata: ••••abcd · 32 char".
 *
 * Non-set fields fall back to schema defaults so the UI never has to guess.
 *
 * @param string $service
 * @param bool   $redact When true (default) returns secret fields as fingerprint
 *                       structs `{ present, last4, length }`. When false returns
 *                       raw plaintext (server-internal only — never AJAX).
 * @return array Flat map: field_name => value | fingerprint-struct
 */
function gh_settings_get( string $service, bool $redact = true ): array {
    $def = gh_settings_service_def( $service );
    if ( ! $def ) return [];
    $raw = gh_settings_read_raw( $service );
    $out = [];

    foreach ( $def['fields'] as $name => $field ) {
        $val = gh_settings_path_get( $raw, $field['path'] ?? [ $name ], $field['default'] ?? null );

        if ( ( $field['type'] ?? 'text' ) === 'secret' ) {
            $str = is_string( $val ) ? $val : '';
            if ( $redact ) {
                $out[ $name ] = gh_settings_fingerprint( $str );
            } else {
                $out[ $name ] = $str;
            }
        } else {
            $out[ $name ] = $val;
        }
    }
    return $out;
}

/**
 * Saves a partial set of fields. For each field declared in the schema the
 * response reports one of:
 *   - 'updated'   : value was different from existing, written successfully.
 *   - 'preserved' : field was not present in the payload, existing kept.
 *   - 'unchanged' : field was in the payload but identical to existing.
 *   - 'cleared'   : non-secret allow_empty field was explicitly emptied.
 *   - 'rejected'  : input violated schema (empty on required, bad enum, bad
 *                   URL, bullet-prefix on a secret, length cap, type cast).
 *
 * Verify-after-write: after update_option() the function re-reads the option
 * and computes the per-field status from the actual stored value. If a WP
 * filter mutates the data on the way down, the response will say so.
 *
 * @return array {
 *     ok:       bool,
 *     fields:   array<field, [status, last4?, length?, value?, error?]>,
 *     option:   string  (option_key actually written)
 * }
 */
function gh_settings_save( string $service, array $payload ): array {
    $def = gh_settings_service_def( $service );
    if ( ! $def ) {
        return [ 'ok' => false, 'fields' => [], 'option' => '', 'error' => "Servizio sconosciuto: {$service}" ];
    }
    $option_key = $def['option_key'];
    $existing   = gh_settings_read_raw( $service );
    $merged     = $existing;
    $statuses   = [];

    foreach ( $def['fields'] as $name => $field ) {
        $type = $field['type'] ?? 'text';
        $path = $field['path'] ?? [ $name ];

        // Field omitted from payload entirely → preserve.
        if ( ! array_key_exists( $name, $payload ) ) {
            $statuses[ $name ] = [ 'status' => 'preserved' ];
            continue;
        }

        $raw = $payload[ $name ];
        if ( is_array( $raw ) || is_object( $raw ) ) {
            $statuses[ $name ] = [ 'status' => 'rejected', 'error' => 'tipo non valido (atteso scalare)' ];
            continue;
        }

        // Reject bullet-prefix as a literal value on secret fields. The UI
        // must never send the redacted placeholder back; if it does, that's a
        // bug we want to see, not silently swallow.
        if ( $type === 'secret' && is_string( $raw ) && preg_match( '/^•+/u', $raw ) ) {
            $statuses[ $name ] = [ 'status' => 'rejected', 'error' => 'placeholder redatto inviato come valore (riempi il campo o lascialo vuoto)' ];
            continue;
        }

        // Coerce/sanitize per type.
        $clean = gh_settings_coerce( $type, $raw, $field );
        if ( is_wp_error( $clean ) ) {
            $statuses[ $name ] = [ 'status' => 'rejected', 'error' => $clean->get_error_message() ];
            continue;
        }

        // Empty-string handling on non-secret fields.
        if ( $clean === '' && $type !== 'secret' ) {
            $allow_empty = $field['allow_empty'] ?? true;
            if ( ! $allow_empty ) {
                $statuses[ $name ] = [ 'status' => 'rejected', 'error' => 'campo obbligatorio' ];
                continue;
            }
            // Tracked as 'cleared' below if the existing value was non-empty.
        }

        // Empty-string on secret means "no change" (intentional UX: empty
        // input = leave as-is). The user must type a new value to update.
        if ( $clean === '' && $type === 'secret' ) {
            $statuses[ $name ] = [ 'status' => 'preserved' ];
            continue;
        }

        // Compare against existing to decide updated vs unchanged vs cleared.
        $current = gh_settings_path_get( $existing, $path, null );
        $current = ( $current === null ) ? '' : $current;
        $changed = ! gh_settings_scalar_equal( $current, $clean );

        gh_settings_path_set( $merged, $path, $clean );

        if ( ! $changed ) {
            $statuses[ $name ] = [ 'status' => 'unchanged' ];
        } elseif ( $clean === '' && $current !== '' ) {
            $statuses[ $name ] = [ 'status' => 'cleared' ];
        } else {
            $statuses[ $name ] = [ 'status' => 'updated' ];
        }
    }

    // Write.
    $write_ok = update_option( $option_key, $merged, $def['autoload'] ?? false );

    // Verify-after-write: re-read and reconcile per-field status against the
    // actual stored value. A field marked 'updated' that doesn't reflect the
    // new value in the post-write read becomes 'rejected (write filtered)'.
    $stored_raw = gh_settings_read_raw( $service );
    foreach ( $def['fields'] as $name => $field ) {
        $st = $statuses[ $name ];
        if ( ! in_array( $st['status'], [ 'updated', 'cleared' ], true ) ) {
            // For preserved/unchanged/rejected we still attach a fingerprint
            // so the UI can show the current saved value.
            $statuses[ $name ] = array_merge(
                $st,
                gh_settings_field_fingerprint( $field, $stored_raw )
            );
            continue;
        }

        $stored_val = gh_settings_path_get( $stored_raw, $field['path'] ?? [ $name ], null );
        $stored_val = ( $stored_val === null ) ? '' : $stored_val;
        $expected   = gh_settings_path_get( $merged,    $field['path'] ?? [ $name ], null );
        $expected   = ( $expected === null ) ? '' : $expected;

        if ( ! gh_settings_scalar_equal( $stored_val, $expected ) ) {
            $statuses[ $name ] = [
                'status' => 'rejected',
                'error'  => 'scrittura filtrata (atteso != stored — verifica filtri update_option/sanitize)',
            ];
            $statuses[ $name ] += gh_settings_field_fingerprint( $field, $stored_raw );
        } else {
            $statuses[ $name ] += gh_settings_field_fingerprint( $field, $stored_raw );
        }
    }

    $any_rejected = false;
    foreach ( $statuses as $st ) {
        if ( ( $st['status'] ?? '' ) === 'rejected' ) { $any_rejected = true; break; }
    }

    return [
        'ok'        => $write_ok !== false && ! $any_rejected,
        'write'     => (bool) $write_ok,
        'option'    => $option_key,
        'fields'    => $statuses,
    ];
}

/**
 * WP_DEBUG-only: dumps the actual stored option (with secrets fingerprinted)
 * so the user can confirm "what's really in the DB" without phpMyAdmin.
 */
function gh_settings_dump_debug( string $service ): array {
    if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
        return [ 'enabled' => false, 'reason' => 'WP_DEBUG non attivo' ];
    }
    $def = gh_settings_service_def( $service );
    if ( ! $def ) return [ 'enabled' => true, 'error' => "Servizio sconosciuto: {$service}" ];

    $raw    = gh_settings_read_raw( $service );
    $masked = gh_settings_mask_secrets( $raw, $def );

    return [
        'enabled'    => true,
        'option_key' => $def['option_key'],
        'present'    => ! empty( $raw ),
        'fields'     => gh_settings_get( $service, true ),
        'raw_masked' => $masked,
    ];
}

// ── Internal helpers ────────────────────────────────────────────────────────

function gh_settings_coerce( string $type, $raw, array $field ) {
    switch ( $type ) {
        case 'url':
            $s = trim( (string) $raw );
            if ( $s === '' ) return '';
            $clean = esc_url_raw( $s, [ 'http', 'https' ] );
            if ( ! $clean ) return new WP_Error( 'url', 'URL non valido (solo http/https)' );
            $max = (int) ( $field['max'] ?? 4096 );
            if ( strlen( $clean ) > $max ) return new WP_Error( 'len', "lunghezza massima {$max}" );
            return $clean;

        case 'secret':
            $s = (string) $raw;
            // Strip control chars (token/cookie are single-line opaque blobs).
            $s = preg_replace( '/[\x00-\x1F\x7F]/u', '', $s );
            $s = trim( $s );
            $max = (int) ( $field['max'] ?? 8192 );
            if ( strlen( $s ) > $max ) return new WP_Error( 'len', "lunghezza massima {$max}" );
            return $s;

        case 'enum':
            $s    = (string) $raw;
            $opts = (array) ( $field['options'] ?? [] );
            if ( ! in_array( $s, $opts, true ) ) return new WP_Error( 'enum', 'valore non ammesso' );
            return $s;

        case 'int':
            if ( ! is_numeric( $raw ) && $raw !== '' ) return new WP_Error( 'int', 'numero intero richiesto' );
            $n = (int) $raw;
            if ( isset( $field['min_num'] ) ) $n = max( (int) $field['min_num'], $n );
            if ( isset( $field['max_num'] ) ) $n = min( (int) $field['max_num'], $n );
            return $n;

        case 'float':
            if ( ! is_numeric( $raw ) && $raw !== '' ) return new WP_Error( 'float', 'numero richiesto' );
            $n = (float) $raw;
            if ( isset( $field['min_num'] ) ) $n = max( (float) $field['min_num'], $n );
            if ( isset( $field['max_num'] ) ) $n = min( (float) $field['max_num'], $n );
            return $n;

        case 'bool':
            return (bool) $raw;

        case 'text':
        default:
            $s = sanitize_text_field( (string) $raw );
            $max = (int) ( $field['max'] ?? 1024 );
            if ( strlen( $s ) > $max ) $s = substr( $s, 0, $max );
            return $s;
    }
}

function gh_settings_path_get( array $arr, array $path, $default = null ) {
    $cur = $arr;
    foreach ( $path as $k ) {
        if ( ! is_array( $cur ) || ! array_key_exists( $k, $cur ) ) return $default;
        $cur = $cur[ $k ];
    }
    return $cur;
}

function gh_settings_path_set( array &$arr, array $path, $value ): void {
    $cur = &$arr;
    $last = count( $path ) - 1;
    foreach ( $path as $i => $k ) {
        if ( $i === $last ) {
            $cur[ $k ] = $value;
            return;
        }
        if ( ! isset( $cur[ $k ] ) || ! is_array( $cur[ $k ] ) ) {
            $cur[ $k ] = [];
        }
        $cur = &$cur[ $k ];
    }
}

function gh_settings_scalar_equal( $a, $b ): bool {
    if ( is_bool( $a ) || is_bool( $b ) ) return (bool) $a === (bool) $b;
    if ( is_int( $a ) || is_int( $b ) || is_float( $a ) || is_float( $b ) ) {
        // Compare as float with epsilon to avoid 1.0 vs 1 mismatches.
        return (float) $a === (float) $b;
    }
    return (string) $a === (string) $b;
}

function gh_settings_fingerprint( string $secret ): array {
    if ( $secret === '' ) return [ 'present' => false, 'last4' => '', 'length' => 0 ];
    $len = strlen( $secret );
    return [
        'present' => true,
        'last4'   => $len > 4 ? substr( $secret, -4 ) : str_repeat( '*', $len ),
        'length'  => $len,
    ];
}

function gh_settings_field_fingerprint( array $field, array $stored_raw ): array {
    $name = $field['path'] ?? [];
    if ( ! $name ) return [];
    $val = gh_settings_path_get( $stored_raw, $name, null );

    if ( ( $field['type'] ?? 'text' ) === 'secret' ) {
        $str = is_string( $val ) ? $val : '';
        return [ 'fingerprint' => gh_settings_fingerprint( $str ) ];
    }

    return [ 'value' => $val ];
}

function gh_settings_mask_secrets( array $raw, array $service_def ): array {
    $out = $raw;
    foreach ( $service_def['fields'] as $name => $field ) {
        if ( ( $field['type'] ?? 'text' ) !== 'secret' ) continue;
        $path = $field['path'] ?? [ $name ];
        $val  = gh_settings_path_get( $out, $path, null );
        if ( is_string( $val ) ) {
            gh_settings_path_set( $out, $path, gh_settings_fingerprint( $val ) );
        }
    }
    return $out;
}
