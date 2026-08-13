<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Sources;

use HiveSync\Core\Source\FeedItem;
use HiveSync\Sources\JsonSource;
use PHPUnit\Framework\TestCase;

/**
 * JsonSource::imageUrls() is the feed-side half of two features:
 * the `media_only` run mode ("Solo media") and the missing-image heal.
 * Before it existed, JsonSource inherited AbstractSource's `return []`,
 * so "Solo media" silently pre-staged nothing for the entire GS feed
 * and the healer had no way to tell a repairable product from one the
 * upstream has no image for.
 */
final class JsonSourceImageUrlsTest extends TestCase
{
    private static function urls(array $data): array
    {
        return (new JsonSource())->imageUrls(new FeedItem(sku: 'SKU', data: $data, raw: []));
    }

    public function testGsNativeFieldIsRead(): void
    {
        $u = 'https://media.goldensneakers.net/products/images/SKU/raw/a.png';
        $this->assertSame([$u], self::urls(['_gs_image_url' => $u]));
    }

    public function testImageFullUrlIsRead(): void
    {
        // What the GS transform actually writes, and what DownloadMedia
        // downloads from — the two must agree or the healer re-queues
        // items the pipeline then can't fix.
        $u = 'https://media.goldensneakers.net/products/images/SKU/raw/a.png';
        $this->assertSame([$u], self::urls(['image_full_url' => $u]));
    }

    public function testFeaturedPrecedenceMatchesDownloadMedia(): void
    {
        // featured_image → image → image_full_url → _gs_image_url:
        // first non-empty wins, and only one featured is emitted.
        $out = self::urls([
            'featured_image' => 'https://cdn.example/first.png',
            'image'          => 'https://cdn.example/second.png',
            'image_full_url' => 'https://cdn.example/third.png',
        ]);
        $this->assertSame(['https://cdn.example/first.png'], $out);
    }

    public function testGalleryFieldsAppendAfterTheFeatured(): void
    {
        $out = self::urls([
            'image_full_url' => 'https://cdn.example/main.png',
            'gallery'        => ['https://cdn.example/g1.png', 'https://cdn.example/g2.png'],
        ]);
        $this->assertSame([
            'https://cdn.example/main.png',
            'https://cdn.example/g1.png',
            'https://cdn.example/g2.png',
        ], $out);
    }

    public function testEmptyImageYieldsNoUrls(): void
    {
        // The GS transform stores '' when the host allowlist rejects the
        // URL, so a rejected image reads as "feed has nothing to attach"
        // — which is exactly what keeps the heal self-terminating.
        $this->assertSame([], self::urls(['image_full_url' => '']));
        $this->assertSame([], self::urls([]));
    }

    public function testNonHttpValuesAreDropped(): void
    {
        $this->assertSame([], self::urls(['image_full_url' => 'ftp://cdn.example/a.png']));
        $this->assertSame([], self::urls(['image_full_url' => '/relative/a.png']));
    }

    public function testDuplicatesAreCollapsed(): void
    {
        // GS sets image_full_url and _gs_image_url to the same joined URL;
        // a gallery echoing the featured must not download it twice.
        $u   = 'https://media.goldensneakers.net/products/images/SKU/raw/a.png';
        $out = self::urls(['image_full_url' => $u, 'gallery' => [$u]]);
        $this->assertSame([$u], $out);
    }

    public function testReturnedListIsSequential(): void
    {
        $out = self::urls([
            'image_full_url' => 'https://cdn.example/main.png',
            'gallery'        => ['', 'https://cdn.example/g1.png'],
        ]);
        $this->assertSame([0, 1], array_keys($out));
    }
}
