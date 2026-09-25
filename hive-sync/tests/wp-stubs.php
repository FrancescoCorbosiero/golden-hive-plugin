<?php
declare(strict_types=1);

/**
 * Minimal WordPress function shims for unit tests that touch transients.
 * This is NOT a WordPress load — it models the ONE behavior that matters
 * for RunCacheTest: a utf8mb4 `wp_options` column (the no-object-cache
 * transient path) truncates a stored string at the first byte that is
 * not part of a valid UTF-8 sequence, exactly as $wpdb->strip_invalid_text
 * does on write. That truncation is what silently destroyed the binary
 * gzcompress RunCache blob before it was base64-wrapped.
 *
 * Pure-PHP code that never calls a transient is unaffected (the shims are
 * only defined when WordPress isn't loaded).
 */

if ( ! function_exists( 'hsync_test_transient_store' ) ) {
    /** @return array<string, mixed> */
    function &hsync_test_transient_store(): array
    {
        static $store = [];
        return $store;
    }
}

if ( ! function_exists( 'hsync_test_longest_valid_utf8_prefix' ) ) {
    /**
     * Return the maximal leading run of bytes that forms valid UTF-8 —
     * what a utf8mb4 text column keeps when handed a value with invalid
     * byte sequences. A single linear regex pass (no O(n^2) probing).
     */
    function hsync_test_longest_valid_utf8_prefix( string $value ): string
    {
        if ( preg_match( '//u', $value ) === 1 ) {
            return $value; // already valid end-to-end
        }
        $pattern = '/\A(?:[\x00-\x7F]'
            . '|[\xC2-\xDF][\x80-\xBF]'
            . '|\xE0[\xA0-\xBF][\x80-\xBF]'
            . '|[\xE1-\xEC][\x80-\xBF]{2}'
            . '|\xED[\x80-\x9F][\x80-\xBF]'
            . '|[\xEE-\xEF][\x80-\xBF]{2}'
            . '|\xF0[\x90-\xBF][\x80-\xBF]{2}'
            . '|[\xF1-\xF3][\x80-\xBF]{3}'
            . '|\xF4[\x80-\x8F][\x80-\xBF]{2})*/';
        return preg_match( $pattern, $value, $m ) === 1 ? $m[0] : '';
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( string $key, mixed $value, int $ttl = 0 ): bool
    {
        $store = &hsync_test_transient_store();
        // Model the DB text column: string values are charset-stripped
        // exactly like wp_options would on write.
        $store[ $key ] = is_string( $value )
            ? hsync_test_longest_valid_utf8_prefix( $value )
            : $value;
        $ttls = &hsync_test_transient_ttls();
        $ttls[ $key ] = $ttl;
        // No object cache: WordPress keeps the expiry in its own option.
        if ( $ttl > 0 && ! hsync_test_ext_cache() ) {
            $options = &hsync_test_option_store();
            $options[ '_transient_timeout_' . $key ] = time() + $ttl;
        }
        return true;
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( string $key ): mixed
    {
        $store = &hsync_test_transient_store();
        return array_key_exists( $key, $store ) ? $store[ $key ] : false;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( string $key ): bool
    {
        $store = &hsync_test_transient_store();
        unset( $store[ $key ] );
        $options = &hsync_test_option_store();
        unset( $options[ '_transient_timeout_' . $key ] );
        return true;
    }
}

// ─── Options + transient expiry (RunCache::touch) ─────────────────
//
// Models the two storage layouts touch() distinguishes:
//   - no persistent object cache: a transient with a TTL is a value row
//     plus a `_transient_timeout_<key>` option holding its expiry;
//   - a persistent object cache: the expiry lives inside the cache item
//     (recorded here per key in hsync_test_transient_ttls()).
// Toggle with hsync_test_ext_cache( true|false ).

if ( ! function_exists( 'hsync_test_option_store' ) ) {
    /** @return array<string, mixed> */
    function &hsync_test_option_store(): array
    {
        static $store = [];
        return $store;
    }
}

if ( ! function_exists( 'hsync_test_transient_ttls' ) ) {
    /** @return array<string, int> key → TTL of the LAST set_transient() */
    function &hsync_test_transient_ttls(): array
    {
        static $ttls = [];
        return $ttls;
    }
}

if ( ! function_exists( 'hsync_test_ext_cache' ) ) {
    function hsync_test_ext_cache( ?bool $set = null ): bool
    {
        static $on = false;
        if ( $set !== null ) $on = $set;
        return $on;
    }
}

if ( ! function_exists( 'hsync_test_reset_wp_stubs' ) ) {
    function hsync_test_reset_wp_stubs(): void
    {
        $t = &hsync_test_transient_store();  $t = [];
        $o = &hsync_test_option_store();     $o = [];
        $l = &hsync_test_transient_ttls();   $l = [];
        hsync_test_ext_cache( false );
    }
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
    function wp_using_ext_object_cache(): bool
    {
        return hsync_test_ext_cache();
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $option, mixed $default = false ): mixed
    {
        $store = &hsync_test_option_store();
        return array_key_exists( $option, $store ) ? $store[ $option ] : $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $option, mixed $value, mixed $autoload = null ): bool
    {
        $store = &hsync_test_option_store();
        $store[ $option ] = $value;
        return true;
    }
}
