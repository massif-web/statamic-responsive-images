<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use Massif\ResponsiveImages\Image\MetadataReader;
use Massif\ResponsiveImages\Image\ResolvedImage;
use Massif\ResponsiveImages\Tests\TestCase;
use RuntimeException;
use Statamic\Assets\Asset;

class MetadataReaderTest extends TestCase
{
    public function test_unreadable_url_returns_failed_metadata(): void
    {
        $reader = new MetadataReader();
        $image = new ResolvedImage(
            asset: null,
            id: 'missing',
            mtime: 1,
            url: '/this/path/does/not/exist.jpg',
        );

        $meta = $reader->read($image);

        $this->assertTrue($meta->failed);
        $this->assertSame(0, $meta->width);
        $this->assertSame(0, $meta->height);
        $this->assertSame('application/octet-stream', $meta->mime);
    }

    public function test_asset_that_throws_returns_failed_metadata(): void
    {
        $asset = $this->getMockBuilder(Asset::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['width'])
            ->getMock();
        $asset->method('width')->willThrowException(new RuntimeException('driver exploded'));

        $reader = new MetadataReader();
        $image = new ResolvedImage(asset: $asset, id: 'bang', mtime: 1, url: null);

        $meta = $reader->read($image);

        $this->assertTrue($meta->failed);
    }

    public function test_valid_image_file_returns_real_metadata(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mr').'.png';
        $png = imagecreatetruecolor(20, 10);
        imagepng($png, $tmp);
        imagedestroy($png);

        try {
            $reader = new MetadataReader();
            $meta = $reader->read(new ResolvedImage(asset: null, id: 'real', mtime: 1, url: $tmp));

            $this->assertFalse($meta->failed);
            $this->assertSame(20, $meta->width);
            $this->assertSame(10, $meta->height);
            $this->assertSame('image/png', $meta->mime);
        } finally {
            @unlink($tmp);
        }
    }
}
