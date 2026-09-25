<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Schedule;

/**
 * LeaseStore over one wp_options row, written with raw SQL so every
 * primitive is a single atomic statement:
 *
 *   insertIfAbsent  INSERT IGNORE      (UNIQUE KEY option_name)
 *   replace         UPDATE … WHERE option_value = <expected>
 *   deleteIf        DELETE … WHERE option_value = <expected>
 *
 * Deliberately NOT through the options API: add_option() checks the
 * object cache first (not atomic), and a cached copy would go stale
 * under a second process. The row is autoload=no and nothing ever
 * get_option()s it, so no cache entry exists to disagree with the DB.
 *
 * Why not a transient (the old tick lock): get + set is two statements,
 * so two cron requests racing inside that window both "acquired". Why
 * not MySQL GET_LOCK: it lives on the connection — a wpdb reconnect
 * mid-run silently drops it, and a read replica behind HyperDB would
 * grant it to everybody.
 */
final class OptionsLeaseStore implements LeaseStore
{
    public const OPTION = 'hsync_runner_lease';

    public function __construct(
        private readonly string $option = self::OPTION,
    ) {}

    public function read(): ?string
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return null;
        $v = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $this->option,
        ) );
        return $v === null ? null : (string) $v;
    }

    public function insertIfAbsent( string $value ): bool
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return false;
        $rows = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $this->option,
            $value,
        ) );
        return $rows === 1;
    }

    public function replace( string $expected, string $value ): bool
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return false;
        $rows = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $value,
            $this->option,
            $expected,
        ) );
        return $rows === 1;
    }

    public function deleteIf( string $expected ): bool
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return false;
        $rows = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $this->option,
            $expected,
        ) );
        return $rows === 1;
    }

    public function clear(): void
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s",
            $this->option,
        ) );
    }
}
