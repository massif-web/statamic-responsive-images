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
use Massif\ResponsiveImages\View\PassthroughRenderer;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

class ResponsiveImageTagTest extends TestCase
{
    private array $preloaded = [];

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

        $this->preloaded = [];
        $preloader = new \Massif\ResponsiveImages\View\Preloader(
            pusher: function (string $stack, string $content) {
                $this->preloaded[] = ['stack' => $stack, 'content' => $content];
            }
        );

        $baseConfig = require __DIR__.'/../../config/responsive-images.php';
        $config = array_replace_recursive($baseConfig, $configOverrides);

        return new ResponsiveImage(
            $resolver, $metadata, $srcset, $urls, $placeholder,
            new PictureRenderer(),
            new \Massif\ResponsiveImages\View\PassthroughRenderer(),
            $preloader,
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

    public function test_svg_src_renders_bare_img_no_picture(): void
    {
        $reader = new class extends MetadataReader {
            public function read(ResolvedImage $image): ImageMetadata
            {
                return new ImageMetadata(100, 50, 'image/svg+xml');
            }
        };

        $html = $this->makeTag([], $reader)->renderFromParams([
            'src' => '/u/logo.svg',
            'alt' => 'logo',
        ]);

        $this->assertStringNotContainsString('<picture>', $html);
        $this->assertStringNotContainsString('srcset=', $html);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('src="/u/logo.svg"', $html);
        $this->assertStringContainsString('alt="logo"', $html);
    }

    public function test_gif_src_renders_bare_img_no_picture(): void
    {
        $reader = new class extends MetadataReader {
            public function read(ResolvedImage $image): ImageMetadata
            {
                return new ImageMetadata(200, 200, 'image/gif');
            }
        };

        $html = $this->makeTag([], $reader)->renderFromParams([
            'src' => '/u/loop.gif',
            'alt' => 'looping',
        ]);

        $this->assertStringNotContainsString('<picture>', $html);
        $this->assertStringNotContainsString('srcset=', $html);
        $this->assertStringContainsString('src="/u/loop.gif"', $html);
    }

    public function test_empty_alt_emits_aria_hidden(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src' => '/p.jpg',
            'alt' => '',
        ]);

        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_svg_forwards_fetchpriority(): void
    {
        $reader = new class extends MetadataReader {
            public function read(ResolvedImage $image): ImageMetadata
            {
                return new ImageMetadata(100, 50, 'image/svg+xml');
            }
        };

        $html = $this->makeTag([], $reader)->renderFromParams([
            'src'           => '/u/logo.svg',
            'alt'           => 'logo',
            'fetchpriority' => 'high',
        ]);

        $this->assertStringContainsString('fetchpriority="high"', $html);
    }

    public function test_quality_param_overrides_all_formats(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'     => '/p.jpg',
            'alt'     => 'x',
            'quality' => 55,
        ]);

        $this->assertStringContainsString('q=55', $html);
        $this->assertStringNotContainsString('q=50', $html);
        $this->assertStringNotContainsString('q=75', $html);
        $this->assertStringNotContainsString('q=82', $html);
    }

    public function test_formats_param_restricts_output_formats(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'     => '/p.jpg',
            'alt'     => 'x',
            'formats' => 'webp,fallback',
        ]);

        $this->assertStringNotContainsString('type="image/avif"', $html);
        $this->assertStringContainsString('type="image/webp"', $html);
        $this->assertStringContainsString('<img', $html);
    }

    public function test_formats_param_accepts_array(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'     => '/p.jpg',
            'alt'     => 'x',
            'formats' => ['avif'],
        ]);

        $this->assertStringContainsString('type="image/avif"', $html);
        $this->assertStringNotContainsString('type="image/webp"', $html);
    }

    public function test_formats_invalid_entries_dropped_silently(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'     => '/p.jpg',
            'alt'     => 'x',
            'formats' => 'bogus,webp',
        ]);

        $this->assertStringContainsString('type="image/webp"', $html);
    }

    public function test_blur_param_passed_through_to_glide(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'  => '/p.jpg',
            'alt'  => 'x',
            'blur' => 20,
        ]);

        $this->assertStringContainsString('blur=20', $html);
    }

    public function test_multiple_passthrough_filters(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'     => '/p.jpg',
            'alt'     => 'x',
            'blur'    => 20,
            'sharpen' => 5,
            'filter'  => 'sepia',
        ]);

        $this->assertStringContainsString('blur=20', $html);
        $this->assertStringContainsString('sharpen=5', $html);
        $this->assertStringContainsString('filter=sepia', $html);
    }

    public function test_invalid_numeric_filter_silently_dropped(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'  => '/p.jpg',
            'alt'  => 'x',
            'blur' => 'not-a-number',
        ]);

        $this->assertStringNotContainsString('blur=', $html);
    }

    public function test_wildcard_resolves_src_from_context(): void
    {
        $tag = $this->makeTag();

        // Inject context + params with a tag suffix.
        $tag->setContext(['hero' => '/uploads/hero.jpg']);
        $tag->setParameters(['alt' => 'sunset']);

        $html = $tag->wildcard('hero');

        $this->assertStringContainsString('<picture>', $html);
        $this->assertStringContainsString('alt="sunset"', $html);
    }

    public function test_wildcard_missing_context_returns_empty(): void
    {
        $tag = $this->makeTag();
        $tag->setContext([]);
        $tag->setParameters([]);

        $html = $tag->wildcard('missing');

        $this->assertSame('', $html);
    }

    public function test_pic_alias_produces_same_output_as_responsive_image(): void
    {
        $cache = new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore);

        $reader = new class extends MetadataReader {
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
        $placeholder = new Placeholder(cache: $cache, fetcher: fn () => ['bytes' => 'P', 'mime' => 'image/jpeg']);

        $baseConfig = require __DIR__.'/../../config/responsive-images.php';

        $args = [$resolver, $metadata, $srcset, $urls, $placeholder, new PictureRenderer(), new \Massif\ResponsiveImages\View\PassthroughRenderer(), new \Massif\ResponsiveImages\View\Preloader()];

        $tag = new ResponsiveImage(...$args, config: $baseConfig);
        $pic = new \Massif\ResponsiveImages\Aliases\Pic(...$args, config: $baseConfig);

        $params = ['src' => '/p.jpg', 'alt' => 'x'];

        $this->assertSame($tag->renderFromParams($params), $pic->renderFromParams($params));
    }

    public function test_pic_default_handle_is_pic(): void
    {
        $this->assertSame('pic', \Massif\ResponsiveImages\Aliases\Pic::$handle);
    }

    public function test_config_default_sizes_is_layout_aware(): void
    {
        $config = require __DIR__.'/../../config/responsive-images.php';

        $this->assertSame(
            '(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw',
            $config['default_sizes']
        );
    }

    public function test_config_tag_alias_default_is_pic(): void
    {
        $config = require __DIR__.'/../../config/responsive-images.php';

        $this->assertSame('pic', $config['tag_alias']);
    }

    public function test_config_preload_defaults_are_auto_on(): void
    {
        $config = require __DIR__.'/../../config/responsive-images.php';

        $this->assertTrue($config['preload']['auto_eager']);
        $this->assertTrue($config['preload']['auto_priority']);
    }

    public function test_preload_pushes_avif_link_when_enabled(): void
    {
        $this->makeTag()->renderFromParams([
            'src'     => '/p.jpg',
            'alt'     => 'x',
            'preload' => true,
        ]);

        $this->assertCount(1, $this->preloaded);
        $this->assertSame('head', $this->preloaded[0]['stack']);
        $this->assertStringContainsString('type="image/avif"', $this->preloaded[0]['content']);
        $this->assertStringContainsString('fetchpriority="high"', $this->preloaded[0]['content']);
    }

    public function test_preload_picks_webp_when_avif_disabled(): void
    {
        $this->makeTag([
            'formats' => ['avif' => ['enabled' => false, 'quality' => 50]],
        ])->renderFromParams([
            'src'     => '/p.jpg',
            'alt'     => 'x',
            'preload' => true,
        ]);

        $this->assertStringContainsString('type="image/webp"', $this->preloaded[0]['content']);
    }

    public function test_preload_sets_loading_eager_and_fetchpriority_high_by_default(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'     => '/p.jpg',
            'alt'     => 'x',
            'preload' => true,
        ]);

        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringContainsString('fetchpriority="high"', $html);
    }

    public function test_preload_respects_explicit_loading(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'     => '/p.jpg',
            'alt'     => 'x',
            'preload' => true,
            'loading' => 'lazy',
        ]);

        $this->assertStringContainsString('loading="lazy"', $html);
    }

    public function test_preload_respects_explicit_fetchpriority(): void
    {
        $html = $this->makeTag()->renderFromParams([
            'src'           => '/p.jpg',
            'alt'           => 'x',
            'preload'       => true,
            'fetchpriority' => 'auto',
        ]);

        $this->assertStringContainsString('fetchpriority="auto"', $html);
    }

    public function test_preload_false_does_not_push(): void
    {
        $this->makeTag()->renderFromParams([
            'src' => '/p.jpg',
            'alt' => 'x',
        ]);

        $this->assertSame([], $this->preloaded);
    }
}
