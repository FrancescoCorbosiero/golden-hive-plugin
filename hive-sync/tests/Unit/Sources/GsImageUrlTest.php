<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Sources;

use HiveSync\Sources\JsonSource;
use PHPUnit\Framework\TestCase;

/**
 * Mirror of golden-hive's rp_rc_gs_join_image_url semantics. Pins the
 * regression this join fixed: the GS flavor used image_full_url ALONE
 * and dropped image_name, so a split payload (base + filename — the
 * shape GS ships from media.goldensneakers.net) yielded a truncated
 * base URL instead of the image. No host logic: any subdomain passes.
 */
final class GsImageUrlTest extends TestCase
{
    private const MEDIA = 'https://media.goldensneakers.net/products/images/2913_KJ8969/raw/';

    public function testBasePlusNameConcatenates(): void
    {
        $this->assertSame(
            self::MEDIA . 'c67b5534062a.png',
            JsonSource::joinImageUrl(self::MEDIA, 'c67b5534062a.png')
        );
    }

    public function testFullUrlInBaseIsKept(): void
    {
        $full = self::MEDIA . 'c67b5534062a.png';
        $this->assertSame($full, JsonSource::joinImageUrl($full, ''));
        $this->assertSame($full, JsonSource::joinImageUrl($full, 'c67b5534062a.png'));
    }

    public function testAbsoluteNameWins(): void
    {
        $abs = 'https://media.goldensneakers.net/other/b.png';
        $this->assertSame($abs, JsonSource::joinImageUrl('https://www.goldensneakers.net/old/', $abs));
    }

    public function testLegacyGlueWithoutSeparatorIsPreserved(): void
    {
        $this->assertSame(
            'https://x.net/img/IMG_123.jpg',
            JsonSource::joinImageUrl('https://x.net/img/IMG_', '123.jpg')
        );
    }
}
