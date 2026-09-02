<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Sources;

use HiveSync\Sources\JsonSource;
use PHPUnit\Framework\TestCase;

/**
 * Mirror of golden-hive's GS image URL contract (Aug 2026 format
 * change): image_full_url may now be the COMPLETE file URL on
 * media.goldensneakers.net with image_name still holding the bare
 * filename — blind concatenation produced '…/x.png/x.png' → 404 —
 * while the legacy folder+filename split must keep joining. Host
 * allowlist: apex + any *.goldensneakers.net over https only.
 */
final class GsImageUrlTest extends TestCase
{
    private const NEW_FULL   = 'https://media.goldensneakers.net/products/images/2913_KJ8969/raw/c67b5534062a.png';
    private const OLD_FOLDER = 'https://www.goldensneakers.net/images/2913_KJ8969/main/';
    private const FEED_URL   = 'https://www.goldensneakers.net/api/assortment-flat/';

    public function testNewFullUrlFormatIsNotDuplicated(): void
    {
        $this->assertSame(self::NEW_FULL, JsonSource::joinImageUrl(self::NEW_FULL, 'c67b5534062a.png'));
        $this->assertSame(self::NEW_FULL, JsonSource::joinImageUrl(self::NEW_FULL, ''));
    }

    public function testPathCheckSurvivesQueryString(): void
    {
        $withQuery = self::NEW_FULL . '?v=2';
        $this->assertSame($withQuery, JsonSource::joinImageUrl($withQuery, 'c67b5534062a.png'));
    }

    public function testLegacyFolderFormatStillJoins(): void
    {
        $this->assertSame(
            self::OLD_FOLDER . 'c67b5534062a.png',
            JsonSource::joinImageUrl(self::OLD_FOLDER, 'c67b5534062a.png')
        );
        // Senza slash finale → esattamente uno slash inserito.
        $this->assertSame(
            'https://media.goldensneakers.net/img/SKU/a.png',
            JsonSource::joinImageUrl('https://media.goldensneakers.net/img/SKU', 'a.png')
        );
    }

    public function testAbsoluteNameWins(): void
    {
        $this->assertSame(self::NEW_FULL, JsonSource::joinImageUrl(self::OLD_FOLDER, self::NEW_FULL));
    }

    public function testSubdomainsAcceptedByAllowlist(): void
    {
        $this->assertTrue(JsonSource::isAllowedImageUrl('https://goldensneakers.net/a.png'));
        $this->assertTrue(JsonSource::isAllowedImageUrl('https://www.goldensneakers.net/a.png'));
        $this->assertTrue(JsonSource::isAllowedImageUrl(self::NEW_FULL));
        $this->assertTrue(JsonSource::isAllowedImageUrl('https://cdn2.goldensneakers.net/a.png'));
    }

    public function testLookalikeThirdPartyAndHttpRejected(): void
    {
        $this->assertFalse(JsonSource::isAllowedImageUrl('https://evilgoldensneakers.net/a.png'));
        $this->assertFalse(JsonSource::isAllowedImageUrl('https://goldensneakers.net.evil.com/a.png'));
        $this->assertFalse(JsonSource::isAllowedImageUrl('https://cdn.example.com/a.png'));
        $this->assertFalse(JsonSource::isAllowedImageUrl('http://media.goldensneakers.net/a.png'));
        $this->assertFalse(JsonSource::isAllowedImageUrl(''));
    }

    // ── Path relativi (Set 2026-09) ──────────────────────────────
    // Lo stesso payload mescola URL completi e path relativi senza
    // origine ('/images/<sku>/main/'): il vecchio formato cartella a
    // cui è stata tolta la parte https://host. Vanno completati, non
    // scartati.

    public function testRootRelativePathGetsFeedOrigin(): void
    {
        $this->assertSame(
            'https://www.goldensneakers.net/images/IH6001/main/',
            JsonSource::absolutizeImageUrl('/images/IH6001/main/', self::FEED_URL)
        );
    }

    public function testOriginFallsBackWhenFeedUrlIsUnusable(): void
    {
        $this->assertSame(
            JsonSource::GS_IMAGE_ORIGIN . '/images/IH6001/main/x.png',
            JsonSource::absolutizeImageUrl('/images/IH6001/main/x.png', '')
        );
        $this->assertSame(
            JsonSource::GS_IMAGE_ORIGIN . '/images/IH6001/main/x.png',
            JsonSource::absolutizeImageUrl('/images/IH6001/main/x.png', 'not-a-url')
        );
    }

    public function testOriginFollowsTheConfiguredFeedHost(): void
    {
        $this->assertSame(
            'https://api.goldensneakers.net/images/IH6001/main/x.png',
            JsonSource::absolutizeImageUrl('/images/IH6001/main/x.png', 'https://api.goldensneakers.net/api/assortment-flat/')
        );
        // http upstream → media comunque su https (l'allowlist esige https).
        $this->assertSame(
            'https://www.goldensneakers.net/images/x.png',
            JsonSource::absolutizeImageUrl('/images/x.png', 'http://www.goldensneakers.net/api/')
        );
    }

    public function testAbsoluteAndProtocolRelativeInputs(): void
    {
        $this->assertSame(self::NEW_FULL, JsonSource::absolutizeImageUrl(self::NEW_FULL, self::FEED_URL));
        $this->assertSame(
            'https://media.goldensneakers.net/a.png',
            JsonSource::absolutizeImageUrl('//media.goldensneakers.net/a.png', self::FEED_URL)
        );
        $this->assertSame('', JsonSource::absolutizeImageUrl('', self::FEED_URL));
    }

    public function testBareFilenameAndOtherSchemesAreLeftAlone(): void
    {
        // Nessuna directory → non sappiamo dove viva: inventare
        // '<origine>/foto.png' scaricherebbe una 404 page come immagine.
        $this->assertSame('foto.png', JsonSource::absolutizeImageUrl('foto.png', self::FEED_URL));
        $this->assertSame('data:image/png;base64,AAAA', JsonSource::absolutizeImageUrl('data:image/png;base64,AAAA', self::FEED_URL));
        // …e a valle la resolve li scarta comunque.
        $this->assertSame('', JsonSource::resolveImageUrl('', 'foto.png', self::FEED_URL));
    }

    public function testResolveRepairsTheRelativeProductAndKeepsTheAbsoluteOne(): void
    {
        // Il prodotto rotto del feed: cartella relativa + filename.
        $this->assertSame(
            'https://www.goldensneakers.net/images/IH6001/main/Screenshot_2026-08-24_at_12.25.46.png',
            JsonSource::resolveImageUrl('/images/IH6001/main/', 'Screenshot_2026-08-24_at_12.25.46.png', self::FEED_URL)
        );
        // Il prodotto sano della stessa risposta: invariato.
        $this->assertSame(
            self::NEW_FULL,
            JsonSource::resolveImageUrl(self::NEW_FULL, 'c67b5534062a.png', self::FEED_URL)
        );
        // Cartella relativa senza filename → resta la cartella, che
        // l'allowlist accetta ma che a valle non è un file: il download
        // fallisce e l'immagine esistente resta intatta.
        $this->assertSame(
            'https://www.goldensneakers.net/images/IH6001/main/',
            JsonSource::resolveImageUrl('/images/IH6001/main/', '', self::FEED_URL)
        );
    }

    public function testResolveStillEnforcesTheHostAllowlist(): void
    {
        // Un feed ospitato altrove non può far scaricare da terze parti
        // via path relativo.
        $this->assertSame(
            '',
            JsonSource::resolveImageUrl('/images/IH6001/main/', 'a.png', 'https://cdn.example.com/api/')
        );
        $this->assertSame('', JsonSource::resolveImageUrl('', '', self::FEED_URL));
    }
}
