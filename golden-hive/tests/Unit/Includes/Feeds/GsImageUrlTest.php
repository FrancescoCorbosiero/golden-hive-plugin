<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Includes\Feeds;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * GS moved its media to a subdomain (media.goldensneakers.net) and has
 * changed the image_full_url/image_name split before. These tests pin
 * the defensive join: NO host logic anywhere — any (sub)domain flows
 * through verbatim — and every plausible base/name split produces a
 * usable URL. The default path stays byte-identical to the legacy
 * concatenation.
 *
 * Process-isolated: loading feed-goldensneakers.php defines the whole
 * rp_rc_gs_* family, and GoldenSneakersSourceTest asserts the
 * "legacy NOT loaded" posture (function_exists === false) in the main
 * process. Separate processes keep both contracts true.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class GsImageUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../../../includes/feeds/feed-goldensneakers.php';
    }

    private const MEDIA = 'https://media.goldensneakers.net/products/images/2913_KJ8969/raw/';

    public function testBasePlusNameConcatenatesLikeLegacy(): void
    {
        $this->assertSame(
            self::MEDIA . 'c67b5534062a.png',
            rp_rc_gs_join_image_url( self::MEDIA, 'c67b5534062a.png' )
        );
    }

    public function testAnySubdomainPassesThroughUntouched(): void
    {
        foreach ( [ 'media', 'cdn2', 'img-eu.static' ] as $sub ) {
            $base = "https://{$sub}.goldensneakers.net/p/raw/";
            $this->assertSame( $base . 'a.png', rp_rc_gs_join_image_url( $base, 'a.png' ) );
        }
    }

    public function testFullUrlInBaseWithEmptyNameIsKept(): void
    {
        $full = self::MEDIA . 'c67b5534062a.png';
        $this->assertSame( $full, rp_rc_gs_join_image_url( $full, '' ) );
    }

    public function testBaseAlreadyEndingWithNameIsNotDoubled(): void
    {
        $full = self::MEDIA . 'c67b5534062a.png';
        $this->assertSame( $full, rp_rc_gs_join_image_url( $full, 'c67b5534062a.png' ) );
    }

    public function testAbsoluteNameWinsOverBase(): void
    {
        $abs = 'https://media.goldensneakers.net/other/b.png';
        $this->assertSame( $abs, rp_rc_gs_join_image_url( 'https://www.goldensneakers.net/old/', $abs ) );
    }

    public function testLegacyGlueWithoutSeparatorIsPreserved(): void
    {
        // Prefix-style base senza slash finale: il glue legacy resta
        // byte-identico (nessun separatore inserito).
        $this->assertSame(
            'https://x.net/img/IMG_123.jpg',
            rp_rc_gs_join_image_url( 'https://x.net/img/IMG_', '123.jpg' )
        );
    }

    public function testEmptyBothYieldsEmpty(): void
    {
        $this->assertSame( '', rp_rc_gs_join_image_url( '', '' ) );
    }

    public function testNormalizersUseTheJoin(): void
    {
        $full = self::MEDIA . 'c67b5534062a.png';

        // Hierarchical: URL completo in image_full_url, image_name ancora
        // presente (la forma che col vecchio concat duplicava il nome).
        $h = rp_rc_gs_normalize_hierarchical( [ [
            'sku'            => '2913_KJ8969',
            'name'           => 'Nike Air Max',
            'brand_name'     => 'Nike',
            'image_full_url' => $full,
            'image_name'     => 'c67b5534062a.png',
            'sizes'          => [],
        ] ] );
        $this->assertSame( $full, $h[0]['image_url'] );

        // Flat: split base + nome.
        $f = rp_rc_gs_normalize_flat( [ [
            'sku'            => '2913_KJ8969',
            'product_name'   => 'Nike Air Max',
            'brand_name'     => 'Nike',
            'image_full_url' => self::MEDIA,
            'image_name'     => 'c67b5534062a.png',
            'size_eu'        => '42',
        ] ] );
        $this->assertSame( $full, $f[0]['image_url'] );
    }
}
