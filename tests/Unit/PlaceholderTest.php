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

    public function test_external_resolver_is_used_when_it_returns_a_uri(): void
    {
        $fetcherCalls = 0;
        $p = new Placeholder(
            cache: new Repository(new ArrayStore),
            fetcher: function () use (&$fetcherCalls) {
                $fetcherCalls++;
                return ['bytes' => 'GLIDE', 'mime' => 'image/jpeg'];
            },
            externalResolver: fn () => 'data:image/png;base64,EXTERNAL',
        );

        $uri = $p->dataUri(new ResolvedImage(null, 'a', 1, '/a.jpg'), $this->config());

        $this->assertSame('data:image/png;base64,EXTERNAL', $uri);
        $this->assertSame(0, $fetcherCalls);
    }

    public function test_external_resolver_falls_through_when_it_returns_null(): void
    {
        $fetcherCalls = 0;
        $p = new Placeholder(
            cache: new Repository(new ArrayStore),
            fetcher: function () use (&$fetcherCalls) {
                $fetcherCalls++;
                return ['bytes' => 'G', 'mime' => 'image/jpeg'];
            },
            externalResolver: fn () => null,
        );

        $image = new ResolvedImage(null, 'a', 1, '/a.jpg');

        $uri1 = $p->dataUri($image, $this->config());
        $uri2 = $p->dataUri($image, $this->config());

        $this->assertSame('data:image/jpeg;base64,'.base64_encode('G'), $uri1);
        $this->assertSame($uri1, $uri2);
        $this->assertSame(1, $fetcherCalls);
    }

    public function test_external_resolver_result_is_not_cached_locally(): void
    {
        $responses = ['data:image/png;base64,A', 'data:image/png;base64,B'];
        $p = new Placeholder(
            cache: new Repository(new ArrayStore),
            fetcher: fn () => ['bytes' => 'G', 'mime' => 'image/jpeg'],
            externalResolver: function () use (&$responses) {
                return array_shift($responses);
            },
        );

        $image = new ResolvedImage(null, 'a', 1, '/a.jpg');

        $uri1 = $p->dataUri($image, $this->config());
        $uri2 = $p->dataUri($image, $this->config());

        $this->assertSame('data:image/png;base64,A', $uri1);
        $this->assertSame('data:image/png;base64,B', $uri2);
    }
}
