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
}
