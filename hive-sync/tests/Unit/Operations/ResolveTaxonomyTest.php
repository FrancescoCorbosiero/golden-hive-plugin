<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Operations;

use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Source\Context;
use HiveSync\Core\Source\FeedItem;
use HiveSync\Operations\Taxonomy\ResolveTaxonomy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Host-resolver call counter: hive-sync resolves names through this
 * global (host-adapter dispatch). The stub serves a canned map and
 * counts invocations so the memoization contract is observable.
 */
if ( ! function_exists( __NAMESPACE__ . '\\install_resolver_stub' ) ) {
    function install_resolver_stub(): void
    {
        if ( function_exists( 'hsync_resolve_taxonomy' ) ) return;

        eval( <<<'PHP'
        function hsync_resolve_taxonomy( string $taxonomy, string $name, array $context = [] ): ?int {
            $GLOBALS['hsync_test_resolver_calls'][] = [ $taxonomy, $name, ! empty( $context['create_missing'] ) ];
            $map = [
                'product_brand|Nike'   => 7,
                'product_cat|Sneakers' => 12,
                'pa_taglia|42'         => 42042,
            ];
            return $map[ $taxonomy . '|' . $name ] ?? null;
        }
        PHP );
    }
}

final class ResolveTaxonomyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        install_resolver_stub();
        $GLOBALS['hsync_test_resolver_calls'] = [];

        // Reset the request-static memo between tests.
        $ref  = new ReflectionClass( ResolveTaxonomy::class );
        $prop = $ref->getProperty( 'termMemo' );
        $prop->setValue( null, [] );
    }

    private function ctx( bool $dryRun = false ): OperationContext
    {
        return new OperationContext(
            base: new Context( runId: 'test', dryRun: $dryRun ),
            sourceId: 'csv',
        );
    }

    private function apply( array $draft, array $params = [] ): array
    {
        $rule = new ResolveTaxonomy();
        $item = new FeedItem( sku: 'SKU1', data: $draft );
        $rule->applyDuringImport( $item, $draft, $params, $this->ctx() );
        return $draft;
    }

    public function testResolvesNamesToIdFields(): void
    {
        $draft = $this->apply( [ 'brands' => [ 'Nike' ], 'categories' => [ 'Sneakers' ] ] );

        $this->assertSame( [ 7 ], $draft['brand_ids'] );
        $this->assertSame( [ 12 ], $draft['category_ids'] );
    }

    public function testRepeatedNamesHitTheResolverOnce(): void
    {
        // 5 item con lo stesso brand: il value-space di un feed è
        // minuscolo — il resolver deve essere pagato una volta per
        // valore distinto, non una per item (~180k dispatch su 10k
        // item senza memo).
        for ( $i = 0; $i < 5; $i++ ) {
            $this->apply( [ 'brands' => [ 'Nike' ] ] );
        }

        $calls = array_filter(
            $GLOBALS['hsync_test_resolver_calls'],
            static fn( array $c ): bool => $c[0] === 'product_brand' && $c[1] === 'Nike'
        );
        $this->assertCount( 1, $calls );
    }

    public function testUnresolvableNamesAreNegativeCached(): void
    {
        // Il nome irrisolvibile è il caso PIÙ costoso senza memo (ogni
        // item ripaga il tentativo di create fallito).
        for ( $i = 0; $i < 3; $i++ ) {
            $draft = $this->apply( [ 'brands' => [ 'MarcaFantasma' ] ] );
            $this->assertArrayNotHasKey( 'brand_ids', $draft );
        }

        $calls = array_filter(
            $GLOBALS['hsync_test_resolver_calls'],
            static fn( array $c ): bool => $c[1] === 'MarcaFantasma'
        );
        $this->assertCount( 1, $calls );
    }

    public function testCreateMissingFlagIsPartOfTheMemoKey(): void
    {
        $this->apply( [ 'brands' => [ 'Nike' ] ], [ 'create_missing' => false ] );
        $this->apply( [ 'brands' => [ 'Nike' ] ], [ 'create_missing' => true ] );

        $calls = array_filter(
            $GLOBALS['hsync_test_resolver_calls'],
            static fn( array $c ): bool => $c[1] === 'Nike'
        );
        $this->assertCount( 2, $calls, 'create_missing diverso → risoluzione separata' );
    }

    public function testDryRunResolvesNothing(): void
    {
        $rule  = new ResolveTaxonomy();
        $draft = [ 'brands' => [ 'Nike' ] ];
        $item  = new FeedItem( sku: 'SKU1', data: $draft );
        $rule->applyDuringImport( $item, $draft, [], $this->ctx( dryRun: true ) );

        $this->assertCount( 0, $GLOBALS['hsync_test_resolver_calls'] );
        $this->assertArrayNotHasKey( 'brand_ids', $draft );
    }
}
