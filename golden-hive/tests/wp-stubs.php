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

if ( ! class_exists( 'WC_Product' ) ) {
    /**
     * Configurable WC_Product double. Only exists when WooCommerce is not
     * loaded; construct with an array of the fields the code under test
     * reads. get_children returns ids resolvable through the
     * wc_get_product() registry stub below.
     */
    class WC_Product {
        public function __construct( private array $data = [] ) {}

        public function get_id(): int              { return (int) ( $this->data['id'] ?? 0 ); }
        public function get_name(): string         { return (string) ( $this->data['name'] ?? '' ); }
        public function get_sku(): string          { return (string) ( $this->data['sku'] ?? '' ); }
        public function get_price(): string        { return (string) ( $this->data['price'] ?? '' ); }
        public function get_sale_price(): string   { return (string) ( $this->data['sale_price'] ?? '' ); }
        public function get_stock_status(): string { return (string) ( $this->data['stock_status'] ?? 'instock' ); }
        public function get_children(): array      { return (array) ( $this->data['children'] ?? [] ); }
        public function is_on_sale(): bool         { return (bool) ( $this->data['on_sale'] ?? false ); }
        public function is_type( string $type ): bool {
            return ( $this->data['type'] ?? 'simple' ) === $type;
        }
        public function get_date_created(): ?DateTimeImmutable {
            $ts = $this->data['created_ts'] ?? null;
            return $ts === null ? null : new DateTimeImmutable( '@' . $ts );
        }
    }
}

// NOTA: nessuno stub di wc_get_product() qui — i Check v2 (src/Checks)
// usano function_exists('wc_get_product') come rilevamento della modalita
// unit-test ("WP non caricato → pass ottimistico") e il contract test lo
// asserisce esplicitamente. I test procedurali coprono i path che non
// idratano figli via wc_get_product.
