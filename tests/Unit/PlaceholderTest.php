<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Massif\ResponsiveImages\Image\Placeholder;
use Massif\ResponsiveImages\Image\ResolvedImage;

class PlaceholderTest extends TestCase
{
    private function config(): array
    {
        return [
            'placeholder' => ['enabled' => true, 'width' => 32, 'blur' => 40, 'quality' => 40],
            'cache'       => ['prefix' => 'respimg', 'ttl' => null],
        ];
    }

    public function test_returns_null_when_disabled(): void
    {
        $p = new Placeholder(
            cache: new Repository(new ArrayStore),
            fetcher: fn () => ['bytes' => 'x', 'mime' => 'image/jpeg'],
        );

        $config = $this->config();
        $config['placeholder']['enabled'] = false;

        $this->assertNull($p->dataUri(
            new ResolvedImage(null, 'a', 1, '/a.jpg'), $config
        ));
    }

    public function test_returns_base64_data_uri_and_caches(): void
    {
        $calls = 0;
        $p = new Placeholder(
            cache: new Repository(new ArrayStore),
            fetcher: function () use (&$calls) {
                $calls++;
                return ['bytes' => 'BINARY', 'mime' => 'image/jpeg'];
            },
        );

        $image = new ResolvedImage(null, 'a', 1, '/a.jpg');

        $uri1 = $p->dataUri($image, $this->config());
        $uri2 = $p->dataUri($image, $this->config());

        $this->assertSame('data:image/jpeg;base64,'.base64_encode('BINARY'), $uri1);
        $this->assertSame($uri1, $uri2);
        $this->assertSame(1, $calls);
    }
}
