<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Massif\ResponsiveImages\Image\ImageMetadata;
use Massif\ResponsiveImages\Image\Metadata;
use Massif\ResponsiveImages\Image\MetadataReader;
use Massif\ResponsiveImages\Image\ResolvedImage;

class MetadataTest extends TestCase
{
    public function test_reads_once_and_caches_by_id_and_mtime(): void
    {
        $reader = new class extends MetadataReader {
            public int $calls = 0;
            public function read(ResolvedImage $image): ImageMetadata
            {
                $this->calls++;
                return new ImageMetadata(1600, 900, 'image/jpeg');
            }
        };

        $cache = new Repository(new ArrayStore);
        $metadata = new Metadata($reader, $cache, prefix: 'respimg');
        $image = new ResolvedImage(asset: null, id: 'abc', mtime: 100, url: '/a.jpg');

        $first  = $metadata->for($image);
        $second = $metadata->for($image);

        $this->assertSame(1600, $first->width);
        $this->assertSame(900, $first->height);
        $this->assertFalse($first->failed);
        $this->assertEquals($first, $second);
        $this->assertSame(1, $reader->calls);
    }

    public function test_new_mtime_invalidates_cache(): void
    {
        $reader = new class extends MetadataReader {
            public int $calls = 0;
            public function read(ResolvedImage $image): ImageMetadata
            {
                $this->calls++;
                return new ImageMetadata(1000, 500, 'image/jpeg');
            }
        };

        $cache = new Repository(new ArrayStore);
        $metadata = new Metadata($reader, $cache, prefix: 'respimg');

        $metadata->for(new ResolvedImage(asset: null, id: 'x', mtime: 1, url: '/x.jpg'));
        $metadata->for(new ResolvedImage(asset: null, id: 'x', mtime: 2, url: '/x.jpg'));

        $this->assertSame(2, $reader->calls);
    }

    public function test_failed_read_is_cached_with_sentinel_ttl(): void
    {
        $reader = new class extends MetadataReader {
            public int $calls = 0;
            public function read(ResolvedImage $image): ImageMetadata
            {
                $this->calls++;
                return ImageMetadata::failed();
            }
        };

        $store = new class extends ArrayStore {
            /** @var array<string, int> */
            public array $puts = [];
            public function put($key, $value, $seconds)
            {
                $this->puts[$key] = (int) $seconds;
                return parent::put($key, $value, $seconds);
            }
        };
        $cache = new Repository($store);

        $metadata = new Metadata($reader, $cache, prefix: 'respimg', metadataTtl: 7_776_000, sentinelTtl: 60);
        $image = new ResolvedImage(asset: null, id: 'bad', mtime: 1, url: '/bad.jpg');

        $first  = $metadata->for($image);
        $second = $metadata->for($image);

        $this->assertTrue($first->failed);
        $this->assertEquals($first, $second);
        $this->assertSame(1, $reader->calls, 'failed result must be cached, not re-read');
        $this->assertSame(60, $store->puts['respimg:meta:bad:1']);
    }

    public function test_successful_read_uses_metadata_ttl(): void
    {
        $reader = new class extends MetadataReader {
            public function read(ResolvedImage $image): ImageMetadata
            {
                return new ImageMetadata(800, 600, 'image/png');
            }
        };

        $store = new class extends ArrayStore {
            /** @var array<string, int> */
            public array $puts = [];
            public function put($key, $value, $seconds)
            {
                $this->puts[$key] = (int) $seconds;
                return parent::put($key, $value, $seconds);
            }
        };
        $cache = new Repository($store);

        $metadata = new Metadata($reader, $cache, prefix: 'respimg', metadataTtl: 7_776_000, sentinelTtl: 60);
        $metadata->for(new ResolvedImage(asset: null, id: 'ok', mtime: 1, url: '/ok.jpg'));

        $this->assertSame(7_776_000, $store->puts['respimg:meta:ok:1']);
    }

    public function test_corrupt_cache_value_is_treated_as_miss(): void
    {
        $reader = new class extends MetadataReader {
            public int $calls = 0;
            public function read(ResolvedImage $image): ImageMetadata
            {
                $this->calls++;
                return new ImageMetadata(1200, 800, 'image/jpeg');
            }
        };

        $cache = new Repository(new ArrayStore);
        $cache->put('respimg:meta:bad:1', 'not-an-array', 3600);

        $metadata = new Metadata($reader, $cache, prefix: 'respimg');
        $meta = $metadata->for(new ResolvedImage(asset: null, id: 'bad', mtime: 1, url: '/b.jpg'));

        $this->assertSame(1200, $meta->width);
        $this->assertSame(1, $reader->calls);
    }
}
