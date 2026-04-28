<?php
declare(strict_types=1);

namespace GH\Workflow\Run;

/**
 * Maps the Workflow tab's schedule preset radio buttons to cron
 * expressions. The existing Jobs tab provides a full cron editor for
 * fine-grained tweaks — this class only exposes a small set of common
 * choices to keep the v2 UI uncluttered.
 *
 * 'custom' is the escape hatch: the user types a raw cron expression
 * which the AJAX layer validates via gh_cron_parse() before saving.
 *
 * 'never' is for the one-shot "Run now" path — we save the job with
 * enabled=false and a placeholder yearly cron so it has a valid record
 * without auto-running.
 */
final class CronPresets
{
    public const NEVER = 'never';

    /** @var array<string, string> */
    private const PRESETS = [
        'hourly'     => '0 * * * *',       // top of every hour
        'every_6h'   => '0 */6 * * *',     // 00:00, 06:00, 12:00, 18:00
        'daily'      => '0 3 * * *',       // every day at 03:00
        'weekly'     => '0 3 * * 1',       // every Monday at 03:00
        // Used only by the one-shot Run-now path; the saved job is
        // disabled so this expression never actually fires.
        self::NEVER  => '0 0 1 1 *',       // Jan 1st 00:00 (effectively yearly placeholder)
    ];

    /** @return string[] */
    public static function ids(): array
    {
        return array_keys(self::PRESETS);
    }

    /**
     * Resolve a preset id to a cron expression. For 'custom' the caller
     * supplies the raw expression; for any other unknown id, return null.
     */
    public static function toCron(string $presetId, string $customCron = ''): ?string
    {
        if ($presetId === 'custom') {
            $custom = trim($customCron);
            return $custom === '' ? null : $custom;
        }
        return self::PRESETS[$presetId] ?? null;
    }

    public static function isOneShot(string $presetId): bool
    {
        return $presetId === self::NEVER;
    }
}
