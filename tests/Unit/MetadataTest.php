<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Massif\ResponsiveImages\Image\Metadata;
use Massif\ResponsiveImages\Image\MetadataReader;
use Massif\ResponsiveImages\Image\ResolvedImage;

class MetadataTest extends TestCase
{
    public function test_reads_once_and_caches_by_id_and_mtime(): void
    {
        $reader = new class extends MetadataReader {
            public int $calls = 0;
            public function read(ResolvedImage $image): array
            {
                $this->calls++;
                return ['width' => 1600, 'height' => 900, 'mime' => 'image/jpeg'];
            }
        };

        $cache = new Repository(new ArrayStore);
        $metadata = new Metadata($reader, $cache, prefix: 'respimg', ttl: null);
        $image = new ResolvedImage(asset: null, id: 'abc', mtime: 100, url: '/a.jpg');

        $first  = $metadata->for($image);
        $second = $metadata->for($image);

        $this->assertSame(1600, $first['width']);
        $this->assertSame(900, $first['height']);
        $this->assertSame($first, $second);
        $this->assertSame(1, $reader->calls);
    }

    public function test_new_mtime_invalidates_cache(): void
    {
        $reader = new class extends MetadataReader {
            public int $calls = 0;
            public function read(ResolvedImage $image): array
            {
                $this->calls++;
                return ['width' => 1000, 'height' => 500, 'mime' => 'image/jpeg'];
            }
        };

        $cache = new Repository(new ArrayStore);
        $metadata = new Metadata($reader, $cache, prefix: 'respimg', ttl: null);

        $metadata->for(new ResolvedImage(asset: null, id: 'x', mtime: 1, url: '/x.jpg'));
        $metadata->for(new ResolvedImage(asset: null, id: 'x', mtime: 2, url: '/x.jpg'));

        $this->assertSame(2, $reader->calls);
    }
}
