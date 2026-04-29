<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Checks\Specifics;

use GH\Checks\Pricing\SaleBelowRegular;
use GH\Core\Check\CheckSeverity;
use PHPUnit\Framework\TestCase;

final class SaleBelowRegularTest extends TestCase
{
    public function test_no_sale_passes_regardless_of_regular(): void
    {
        self::assertTrue(SaleBelowRegular::verdict('99', '',  CheckSeverity::Warn)->passed);
        self::assertTrue(SaleBelowRegular::verdict('',   '',  CheckSeverity::Warn)->passed);
        self::assertTrue(SaleBelowRegular::verdict('99', '0', CheckSeverity::Warn)->passed,
            'sale=0 is treated as no-sale');
    }

    public function test_sale_strictly_below_regular_passes(): void
    {
        self::assertTrue(SaleBelowRegular::verdict('100', '79.99', CheckSeverity::Warn)->passed);
        self::assertTrue(SaleBelowRegular::verdict('100', '99.99', CheckSeverity::Warn)->passed);
    }

    public function test_sale_equal_to_regular_fails(): void
    {
        $r = SaleBelowRegular::verdict('100', '100', CheckSeverity::Warn);
        self::assertFalse($r->passed);
        self::assertStringContainsString('>= regular', $r->message);
    }

    public function test_sale_above_regular_fails(): void
    {
        $r = SaleBelowRegular::verdict('100', '120', CheckSeverity::Warn);
        self::assertFalse($r->passed);
        self::assertSame(120.0, $r->details['sale']);
        self::assertSame(100.0, $r->details['regular']);
    }

    public function test_sale_set_but_regular_missing_fails(): void
    {
        $r = SaleBelowRegular::verdict('', '50', CheckSeverity::Warn);
        self::assertFalse($r->passed);
        self::assertSame('sale_set_but_regular_missing', $r->message);
    }

    public function test_sale_set_but_regular_zero_fails(): void
    {
        $r = SaleBelowRegular::verdict('0', '50', CheckSeverity::Warn);
        self::assertFalse($r->passed);
        self::assertSame('sale_set_but_regular_missing', $r->message);
    }

    public function test_severity_propagates_to_failures(): void
    {
        $r = SaleBelowRegular::verdict('100', '120', CheckSeverity::Block);
        self::assertSame(CheckSeverity::Block, $r->severity);
        self::assertTrue($r->isBlocking());
    }

    public function test_passes_carry_warn_severity_by_default(): void
    {
        $r = SaleBelowRegular::verdict('100', '50', CheckSeverity::Block);
        self::assertTrue($r->passed);
        // Severity on a pass is meaningless but should not blow up the
        // executor — pass results never count as blocking.
        self::assertFalse($r->isBlocking());
    }
}
