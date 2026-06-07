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
        return true;
    }
}
