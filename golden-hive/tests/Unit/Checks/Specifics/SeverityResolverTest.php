<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Checks\Specifics;

use GH\Checks\Support\Severity;
use GH\Core\Check\CheckSeverity;
use PHPUnit\Framework\TestCase;

final class SeverityResolverTest extends TestCase
{
    public function test_explicit_block_param_overrides_default(): void
    {
        self::assertSame(
            CheckSeverity::Block,
            Severity::fromParams(['severity' => 'block'], CheckSeverity::Warn),
        );
    }

    public function test_explicit_warn_param_overrides_default(): void
    {
        self::assertSame(
            CheckSeverity::Warn,
            Severity::fromParams(['severity' => 'warn'], CheckSeverity::Block),
        );
    }

    public function test_missing_or_invalid_param_falls_back_to_default(): void
    {
        self::assertSame(
            CheckSeverity::Warn,
            Severity::fromParams([], CheckSeverity::Warn),
        );
        self::assertSame(
            CheckSeverity::Block,
            Severity::fromParams(['severity' => 'critical'], CheckSeverity::Block),
        );
    }

    public function test_case_insensitive(): void
    {
        self::assertSame(
            CheckSeverity::Block,
            Severity::fromParams(['severity' => 'BLOCK'], CheckSeverity::Warn),
        );
        self::assertSame(
            CheckSeverity::Warn,
            Severity::fromParams(['severity' => '  Warn  '], CheckSeverity::Block),
            'trimmed too?',
        );
    }

    public function test_param_spec_shape(): void
    {
        $spec = Severity::paramSpec('block');
        self::assertSame('enum', $spec['type']);
        self::assertSame(['warn', 'block'], $spec['options']);
        self::assertSame('block', $spec['default']);
    }
}
