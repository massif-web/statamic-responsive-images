<?php

namespace Massif\ResponsiveImages\Tests\Feature;

use Massif\ResponsiveImages\Tests\TestCase;
use Massif\ResponsiveImages\Tags\ResponsiveImage;
use Massif\ResponsiveImages\Image\ResolvedImage;
use Massif\ResponsiveImages\Image\ImageMetadata;
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
    private function makeTag(array $configOverrides = [], ?MetadataReader $reader = null): ResponsiveImage
    {
        $cache = new Repository(new ArrayStore);

        $reader ??= new class extends MetadataReader {
            public function read(ResolvedImage $image): ImageMetadata
            {
                return new ImageMetadata(1600, 900, 'image/jpeg');
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

    public function test_class_without_wrapper_lands_on_img(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'   => '/p.jpg',
            'alt'   => 'x',
            'class' => 'rounded shadow',
        ]);

        $this->assertStringContainsString('class="rounded shadow"', $html);
        $this->assertStringNotContainsString('<figure', $html);
        $this->assertStringNotContainsString('<div', $html);
    }

    public function test_class_and_img_class_merge_without_wrapper(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'       => '/p.jpg',
            'alt'       => 'x',
            'class'     => 'rounded',
            'img_class' => 'object-cover',
        ]);

        $this->assertStringContainsString('class="object-cover rounded"', $html);
    }

    public function test_class_with_figure_goes_on_wrapper(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'    => '/p.jpg',
            'alt'    => 'x',
            'class'  => 'card',
            'figure' => true,
        ]);

        $this->assertStringContainsString('<figure class="card">', $html);
        $this->assertStringNotContainsString('class="card"', substr($html, strpos($html, '<img')));
    }

    public function test_figure_auto_captions_from_alt(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'    => '/p.jpg',
            'alt'    => 'A sunset',
            'figure' => true,
        ]);

        $this->assertStringContainsString('<figcaption>A sunset</figcaption>', $html);
    }

    public function test_figure_caption_explicit_overrides_alt(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'     => '/p.jpg',
            'alt'     => 'A sunset',
            'figure'  => true,
            'caption' => 'Shot in Lisbon',
        ]);

        $this->assertStringContainsString('<figcaption>Shot in Lisbon</figcaption>', $html);
        $this->assertStringNotContainsString('A sunset</figcaption>', $html);
    }

    public function test_figure_caption_can_be_disabled(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'     => '/p.jpg',
            'alt'     => 'A sunset',
            'figure'  => true,
            'caption' => 'false',
        ]);

        $this->assertStringContainsString('<figure>', $html);
        $this->assertStringNotContainsString('<figcaption', $html);
    }

    public function test_failed_metadata_renders_bare_img(): void
    {
        $reader = new class extends MetadataReader {
            public function read(ResolvedImage $image): ImageMetadata
            {
                return ImageMetadata::failed();
            }
        };

        $html = $this->makeTag([], $reader)->renderFromParams([
            'src'   => '/uploads/broken.jpg',
            'alt'   => 'broken thing',
            'class' => 'hero',
        ]);

        $this->assertStringNotContainsString('<picture>', $html);
        $this->assertStringNotContainsString('srcset=', $html);
        $this->assertStringNotContainsString('width=', $html);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('src="/uploads/broken.jpg"', $html);
        $this->assertStringContainsString('alt="broken thing"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('class="hero"', $html);
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
