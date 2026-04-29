<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Checks\Specifics;

use GH\Checks\Media\HasImages;
use PHPUnit\Framework\TestCase;

final class HasImagesTest extends TestCase
{
    public function test_count_with_no_images(): void
    {
        self::assertSame(0, HasImages::count(0, []));
    }

    public function test_featured_only_counts_one(): void
    {
        self::assertSame(1, HasImages::count(42, []));
    }

    public function test_gallery_only_counts_each(): void
    {
        self::assertSame(3, HasImages::count(0, [10, 11, 12]));
    }

    public function test_featured_plus_gallery_sums(): void
    {
        self::assertSame(4, HasImages::count(42, [10, 11, 12]));
    }

    public function test_zero_or_negative_gallery_ids_are_dropped(): void
    {
        // featured=42 (counts as 1) + gallery [11, 12] valid (counts as 2) = 3
        self::assertSame(3, HasImages::count(42, [0, 11, -5, 12]));
    }

    public function test_string_ids_in_gallery_are_coerced(): void
    {
        self::assertSame(3, HasImages::count(0, ['10', '11', 12]));
    }
}
