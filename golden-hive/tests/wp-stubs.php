<?php
declare(strict_types=1);

/**
 * Minimal WordPress shims for unit tests that exercise procedural code
 * under includes/ (which is guarded by `defined('ABSPATH') || exit`).
 * This is NOT a WordPress load — only the handful of primitives the
 * tested files touch, each guarded so a real WordPress environment
 * always wins. Mirrors the posture of hive-sync/tests/wp-stubs.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        /** @var array<string, array<int, string>> */
        private array $errors = [];

        public function __construct( string|int $code = '', string $message = '' ) {
            if ( $code !== '' ) {
                $this->errors[ (string) $code ][] = $message;
            }
        }

        public function get_error_code(): string|int {
            foreach ( $this->errors as $code => $_ ) {
                return $code;
            }
            return '';
        }

        public function get_error_message(): string {
            foreach ( $this->errors as $messages ) {
                return $messages[0] ?? '';
            }
            return '';
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( mixed $thing ): bool {
        return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( 'wp_timezone' ) ) {
    /** Tests run against a fixed timezone for determinism. */
    function wp_timezone(): DateTimeZone {
        return new DateTimeZone( 'UTC' );
    }
}
