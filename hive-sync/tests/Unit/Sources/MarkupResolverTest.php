<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Sources;

use HiveSync\Sources\MarkupResolver;
use PHPUnit\Framework\TestCase;

/**
 * Locks the negative-operator semantics on missing fields: catch-all
 * rules like "_gs_brand not_in [Nike, Adidas] → +45%" must also cover
 * rows that don't declare the field at all — with the old blanket
 * null bail-out those products fell through to the flat fallback
 * (often 0%) and went live at cost price.
 */
final class MarkupResolverTest extends TestCase
{
    private function rules(): array
    {
        return MarkupResolver::normalize( [
            [ 'field' => '_gs_brand', 'operator' => 'in', 'value' => 'Nike, Adidas', 'percent' => 20 ],
            [ 'field' => '_gs_brand', 'operator' => 'not_in', 'value' => 'Nike, Adidas', 'percent' => 45 ],
        ] );
    }

    public function testPositiveMatchWins(): void
    {
        $this->assertSame( 1.2, MarkupResolver::multiplierFor( [ '_gs_brand' => 'Nike' ], $this->rules(), 0.0 ) );
    }

    public function testThirdPartyBrandHitsTheCatchAll(): void
    {
        $this->assertSame( 1.45, MarkupResolver::multiplierFor( [ '_gs_brand' => 'Saucony' ], $this->rules(), 0.0 ) );
    }

    public function testMissingFieldHitsTheNegativeCatchAll(): void
    {
        // LA regression: riga senza _gs_brand → deve matchare il
        // not_in catch-all (+45%), NON scivolare sul fallback 0%.
        $this->assertSame( 1.45, MarkupResolver::multiplierFor( [ 'name' => 'Runner X' ], $this->rules(), 0.0 ) );
    }

    public function testMissingFieldStillSkipsPositiveOperators(): void
    {
        $rules = MarkupResolver::normalize( [
            [ 'field' => '_gs_brand', 'operator' => 'equals', 'value' => 'Nike', 'percent' => 20 ],
        ] );
        // Campo assente + operatore positivo → nessun match → fallback.
        $this->assertSame( 1.1, MarkupResolver::multiplierFor( [ 'name' => 'X' ], $rules, 10.0 ) );
    }

    public function testNotEqualsMatchesMissingField(): void
    {
        $rules = MarkupResolver::normalize( [
            [ 'field' => '_sf_category', 'operator' => 'not_equals', 'value' => 'borse', 'percent' => 30 ],
        ] );
        $this->assertSame( 1.3, MarkupResolver::multiplierFor( [], $rules, 0.0 ) );
        // E il campo presente-e-uguale continua a NON matchare.
        $this->assertSame( 1.0, MarkupResolver::multiplierFor( [ '_sf_category' => 'Borse' ], $rules, 0.0 ) );
    }

    public function testRuleMultiplierReturnsNullWithoutMatch(): void
    {
        $rules = MarkupResolver::normalize( [
            [ 'field' => '_sf_brand', 'operator' => 'equals', 'value' => 'Guess', 'percent' => 25 ],
        ] );
        $this->assertNull( MarkupResolver::ruleMultiplier( [ '_sf_brand' => 'Liu Jo' ], $rules ) );
        $this->assertSame( 1.25, MarkupResolver::ruleMultiplier( [ '_sf_brand' => 'guess' ], $rules ) );
    }
}
