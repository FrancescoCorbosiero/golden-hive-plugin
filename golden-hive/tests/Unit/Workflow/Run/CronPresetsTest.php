<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Workflow\Run;

use GH\Workflow\Run\CronPresets;
use PHPUnit\Framework\TestCase;

final class CronPresetsTest extends TestCase
{
    public function test_known_presets_resolve_to_cron_strings(): void
    {
        self::assertSame('0 * * * *',   CronPresets::toCron('hourly'));
        self::assertSame('0 */6 * * *', CronPresets::toCron('every_6h'));
        self::assertSame('0 3 * * *',   CronPresets::toCron('daily'));
        self::assertSame('0 3 * * 1',   CronPresets::toCron('weekly'));
    }

    public function test_never_preset_returns_a_yearly_placeholder(): void
    {
        // The 'never' preset is the one-shot Run-now path: the job is
        // saved with enabled=false so this expression never fires, but
        // gh_jobs_save() requires a valid cron to record the job.
        self::assertSame('0 0 1 1 *', CronPresets::toCron(CronPresets::NEVER));
        self::assertTrue(CronPresets::isOneShot(CronPresets::NEVER));
        self::assertFalse(CronPresets::isOneShot('daily'));
    }

    public function test_custom_returns_user_supplied_cron(): void
    {
        self::assertSame('15 4 * * *', CronPresets::toCron('custom', '15 4 * * *'));
        self::assertSame('15 4 * * *', CronPresets::toCron('custom', '  15 4 * * *  '));
    }

    public function test_custom_with_empty_input_returns_null(): void
    {
        self::assertNull(CronPresets::toCron('custom', ''));
        self::assertNull(CronPresets::toCron('custom', '   '));
    }

    public function test_unknown_preset_returns_null(): void
    {
        self::assertNull(CronPresets::toCron('quarterly'));
        self::assertNull(CronPresets::toCron(''));
    }

    public function test_ids_includes_all_named_presets(): void
    {
        $ids = CronPresets::ids();
        self::assertContains('hourly', $ids);
        self::assertContains('every_6h', $ids);
        self::assertContains('daily', $ids);
        self::assertContains('weekly', $ids);
        self::assertContains(CronPresets::NEVER, $ids);
    }
}
