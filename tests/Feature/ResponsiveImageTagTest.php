<?php

namespace Massif\ResponsiveImages\Tests\Feature;

use Massif\ResponsiveImages\Tests\TestCase;
use Massif\ResponsiveImages\Tags\ResponsiveImage;
use Massif\ResponsiveImages\Image\ResolvedImage;
use Massif\ResponsiveImages\Image\ImageResolver;
use Massif\ResponsiveImages\Image\Metadata;
use Massif\ResponsiveImages\Image\MetadataReader;
use Massif\ResponsiveImages\Image\SrcsetBuilder;
use Massif\ResponsiveImages\Image\UrlBuilder;
use Massif\ResponsiveImages\Image\Placeholder;
use Massif\ResponsiveImages\View\PictureRenderer;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

class ResponsiveImageTagTest extends TestCase
{
    private function makeTag(array $configOverrides = []): ResponsiveImage
    {
        $cache = new Repository(new ArrayStore);

        $reader = new class extends MetadataReader {
            public function read(ResolvedImage $image): array
            {
                return ['width' => 1600, 'height' => 900, 'mime' => 'image/jpeg'];
            }
        };

        $resolver    = new ImageResolver(assetLookup: fn () => null);
        $metadata    = new Metadata($reader, $cache);
        $srcset      = new SrcsetBuilder();
        $urls        = new UrlBuilder(urlFactory: function ($img, $params) {
            ksort($params);
            return '/img/'.$img->id.'?'.http_build_query($params);
        });
        $placeholder = new Placeholder(
            cache: $cache,
            fetcher: fn () => ['bytes' => 'P', 'mime' => 'image/jpeg'],
        );

        $baseConfig = require __DIR__.'/../../config/responsive-images.php';
        $config = array_replace_recursive($baseConfig, $configOverrides);

        return new ResponsiveImage(
            $resolver, $metadata, $srcset, $urls, $placeholder,
            new PictureRenderer(),
            config: $config,
        );
    }

    public function test_renders_picture_for_plain_url(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src' => '/uploads/photo.jpg',
            'alt' => 'hi',
        ]);

        $this->assertStringContainsString('<picture>', $html);
        $this->assertStringContainsString('type="image/avif"', $html);
        $this->assertStringContainsString('type="image/webp"', $html);
        $this->assertStringContainsString('alt="hi"', $html);
        $this->assertStringContainsString('width="1600"', $html);
    }

    public function test_ratio_forces_height_on_srcset(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'   => '/p.jpg',
            'alt'   => 'x',
            'ratio' => '16/9',
        ]);

        $this->assertStringContainsString('h=', $html);
        $this->assertStringContainsString('fit=crop_focal', $html);
    }

    public function test_no_ratio_omits_height_and_uses_contain(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src' => '/p.jpg',
            'alt' => 'x',
        ]);

        // srcset/source URLs should carry w + fit=contain but no h query param
        $this->assertStringNotContainsString('&amp;h=', $html);
        $this->assertStringNotContainsString('?h=', $html);
        $this->assertStringContainsString('fit=contain', $html);
    }

    public function test_empty_src_returns_empty_string(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src' => null,
            'alt' => 'x',
        ]);

        $this->assertSame('', $html);
    }

    public function test_disabling_avif_drops_avif_source(): void
    {
        $html = $this->makeTag([
            'formats' => ['avif' => ['enabled' => false, 'quality' => 50]],
        ])->renderFromParams([
            'src' => '/p.jpg',
            'alt' => 'x',
        ]);

        $this->assertStringNotContainsString('image/avif', $html);
        $this->assertStringContainsString('image/webp', $html);
    }

    public function test_art_direction_sources(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src' => '/desk.jpg',
            'alt' => 'x',
            'sources' => [
                ['src' => '/mob.jpg',  'media' => '(max-width: 768px)'],
                ['src' => '/desk.jpg'],
            ],
        ]);

        $this->assertStringContainsString('media="(max-width: 768px)"', $html);
    }
}
