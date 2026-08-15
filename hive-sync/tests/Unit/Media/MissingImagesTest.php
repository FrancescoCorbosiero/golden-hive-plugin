<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Media;

use HiveSync\Media\MissingImages;
use PHPUnit\Framework\TestCase;

/**
 * Covers the WP-free half of the Media tab repair: deciding WHICH
 * configured import job the repair should run through.
 *
 * The scan queries themselves are SQL and need a live WP + Woo to
 * exercise — they're verified against a real install, not here. What
 * IS testable is the job-selection policy, and getting it wrong has a
 * concrete cost: silently defaulting to the wrong feed would repair
 * products with another supplier's images.
 */
final class MissingImagesTest extends TestCase
{
    private static function job(int $id, string $ref, bool $enabled = true, string $type = 'source.import'): array
    {
        return [
            'id'            => $id,
            'runnable_type' => $type,
            'runnable_ref'  => $ref,
            'enabled'       => $enabled,
        ];
    }

    public function testImportJobsKeepsOnlySourceImports(): void
    {
        $jobs = [
            self::job(1, 'json/gs-prod'),
            self::job(2, 'cleanup-orphans', true, 'rule'),
            self::job(3, 'csv/sf-prod'),
        ];

        $out = MissingImages::importJobs($jobs);

        $this->assertCount(2, $out);
        $this->assertSame([1, 3], array_column($out, 'id'));
    }

    public function testImportJobsRejectsMalformedRefs(): void
    {
        // runnable_ref must be '<source_id>/<config_slug>' — without the
        // separator there's no config to resolve, so the repair would
        // run against an empty config and report a clean "0 repaired".
        $out = MissingImages::importJobs([ self::job(1, 'json-no-slash') ]);
        $this->assertSame([], $out);
    }

    public function testImportJobsIgnoresNonArrayRows(): void
    {
        $out = MissingImages::importJobs([ 'garbage', 42, null, self::job(1, 'json/gs-prod') ]);
        $this->assertCount(1, $out);
    }

    public function testSingleImportJobIsChosenEvenWhenDisabled(): void
    {
        // One import job = no ambiguity. Disabled just means the cron
        // isn't firing; it's still the only configured feed, and the
        // operator explicitly asked for a repair.
        $jobs = [ self::job(1, 'json/gs-prod', false) ];

        $picked = MissingImages::pickDefaultJob($jobs);

        $this->assertNotNull($picked);
        $this->assertSame(1, $picked['id']);
    }

    public function testTheSingleEnabledJobWinsWhenSeveralExist(): void
    {
        $jobs = [
            self::job(1, 'json/gs-prod', false),
            self::job(2, 'csv/sf-prod',  true),
            self::job(3, 'json/gs-test', false),
        ];

        $picked = MissingImages::pickDefaultJob($jobs);

        $this->assertNotNull($picked);
        $this->assertSame(2, $picked['id']);
    }

    public function testAmbiguousSelectionRefusesToGuess(): void
    {
        // Two live feeds: picking one would repair products with the
        // wrong supplier's images. The UI asks instead.
        $jobs = [
            self::job(1, 'json/gs-prod', true),
            self::job(2, 'csv/sf-prod',  true),
        ];

        $this->assertNull(MissingImages::pickDefaultJob($jobs));
    }

    public function testAllDisabledAndAmbiguousRefusesToGuess(): void
    {
        $jobs = [
            self::job(1, 'json/gs-prod', false),
            self::job(2, 'csv/sf-prod',  false),
        ];

        $this->assertNull(MissingImages::pickDefaultJob($jobs));
    }

    public function testNoImportJobsYieldsNoDefault(): void
    {
        $this->assertNull(MissingImages::pickDefaultJob([]));
        $this->assertNull(MissingImages::pickDefaultJob([ self::job(1, 'some-rule', true, 'rule') ]));
    }

    public function testRuleJobsNeverBecomeTheDefault(): void
    {
        // A rule job carries no source config — dispatching a media
        // repair through it could only fail.
        $jobs = [
            self::job(1, 'publish-drafts', true, 'rule'),
            self::job(2, 'json/gs-prod',   true),
        ];

        $picked = MissingImages::pickDefaultJob($jobs);

        $this->assertNotNull($picked);
        $this->assertSame(2, $picked['id']);
    }
}
