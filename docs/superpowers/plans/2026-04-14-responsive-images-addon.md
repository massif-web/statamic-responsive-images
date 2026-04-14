# Responsive Images Addon Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a production-ready Statamic 6 addon `Massif\ResponsiveImages` that exposes a single Antlers tag `{{ responsive_image }}` emitting `<picture>` output with AVIF/WebP/fallback sources, blurred LQIP, CLS-safe intrinsic dimensions, and Next.js-inspired srcset generation.

**Architecture:** Thin Antlers tag class orchestrates small, pure, independently-testable units (`ImageResolver`, `Metadata`, `SrcsetBuilder`, `UrlBuilder`, `Placeholder`, `PictureRenderer`). All image encoding is delegated to Statamic's Glide pipeline. Metadata and placeholder data are cached forever keyed by asset id + mtime.

**Tech Stack:** PHP 8.2+, Laravel 11, Statamic 6, Intervention/Image (transitively via Glide), PHPUnit + Orchestra Testbench for tests.

**Reference:** See `docs/superpowers/specs/2026-04-14-responsive-images-addon-design.md` for the full approved spec.

---

## File Structure

```

├── composer.json
├── README.md
├── phpunit.xml
├── config/responsive-images.php
├── src/
│   ├── ServiceProvider.php
│   ├── Tags/ResponsiveImage.php
│   ├── Image/
│   │   ├── ImageResolver.php
│   │   ├── ResolvedImage.php
│   │   ├── Metadata.php
│   │   ├── MetadataReader.php
│   │   ├── SrcsetBuilder.php
│   │   ├── UrlBuilder.php
│   │   └── Placeholder.php
│   └── View/
│       └── PictureRenderer.php
└── tests/
    ├── TestCase.php
    ├── Unit/
    │   ├── SrcsetBuilderTest.php
    │   ├── UrlBuilderTest.php
    │   ├── MetadataTest.php
    │   ├── PlaceholderTest.php
    │   ├── ImageResolverTest.php
    │   └── PictureRendererTest.php
    └── Feature/
        └── ResponsiveImageTagTest.php
```

Responsibilities per file:

- `ServiceProvider.php` — register tag, publish config, merge default config.
- `Tags/ResponsiveImage.php` — Antlers entry point. Parse params, call collaborators, return HTML. Thin orchestrator only.
- `Image/ImageResolver.php` — turn a `src` param (Asset / path / URL) into a `ResolvedImage` value object.
- `Image/ResolvedImage.php` — immutable DTO: `{asset?, url, path?, id, mtime}`.
- `Image/Metadata.php` — cached wrapper: `for(ResolvedImage): MetadataResult`. Returns width/height/mime/placeholder.
- `Image/MetadataReader.php` — actually reads pixels from disk (Intervention). Only hit on cache miss.
- `Image/SrcsetBuilder.php` — pure. `(sourceWidth, config, widthsOverride): int[]`.
- `Image/UrlBuilder.php` — pure. `(ResolvedImage, width, height?, format, fit?, quality): string`. Wraps Statamic Glide helper.
- `Image/Placeholder.php` — produces base64 data URI via one synchronous Glide request per asset per mtime.
- `View/PictureRenderer.php` — only class that emits HTML. Takes data, returns string.

---

## Task 1: Addon Scaffold

**Files:**
- Create: `composer.json`
- Create: `src/ServiceProvider.php`
- Create: `phpunit.xml`
- Create: `tests/TestCase.php`

- [ ] **Step 1: Create composer.json**

```json
{
    "name": "massif/responsive-images",
    "description": "Production-ready responsive image Antlers tag for Statamic 6.",
    "type": "statamic-addon",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "statamic/cms": "^6.0"
    },
    "require-dev": {
        "orchestra/testbench": "^9.0",
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Massif\\ResponsiveImages\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Massif\\ResponsiveImages\\Tests\\": "tests/"
        }
    },
    "extra": {
        "statamic": {
            "name": "Responsive Images",
            "description": "Responsive image Antlers tag"
        },
        "laravel": {
            "providers": [
                "Massif\\ResponsiveImages\\ServiceProvider"
            ]
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

- [ ] **Step 2: Create ServiceProvider stub**

```php
<?php

namespace Massif\ResponsiveImages;

use Statamic\Providers\AddonServiceProvider;
use Massif\ResponsiveImages\Tags\ResponsiveImage;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [
        ResponsiveImage::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(
            __DIR__ . '/../config/responsive-images.php',
            'responsive-images'
        );
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__ . '/../config/responsive-images.php' => config_path('responsive-images.php'),
        ], 'responsive-images-config');
    }
}
```

Note: `ResponsiveImage` class doesn't exist yet. That's fine — Task 9 creates it. For now, comment out the import and `$tags` entry if you want `composer dump` to succeed early, and uncomment in Task 9.

- [ ] **Step 3: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 4: Create base TestCase**

```php
<?php

namespace Massif\ResponsiveImages\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Massif\ResponsiveImages\ServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \Statamic\Providers\StatamicServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('statamic.editions.pro', true);
    }
}
```

- [ ] **Step 5: Install and run**

```bash
composer install
vendor/bin/phpunit
```

Expected: "No tests executed" (we have none yet). Composer autoload should succeed.

- [ ] **Step 6: Commit**

```bash
git add composer.json phpunit.xml src/ServiceProvider.php tests/TestCase.php
git commit -m "feat(responsive-images): scaffold addon"
```

---

## Task 2: Config File

**Files:**
- Create: `config/responsive-images.php`

- [ ] **Step 1: Write config file**

```php
<?php

return [
    // Srcset generation (next/image model).
    // The srcset pool is device_sizes ∪ image_sizes, filtered to
    // widths <= the source image's intrinsic width.
    'device_sizes' => [640, 750, 828, 1080, 1200, 1920, 2048, 3840],
    'image_sizes'  => [16, 32, 48, 64, 96, 128, 256, 384],

    // Default sizes attribute when the tag caller does not provide one.
    'default_sizes' => '100vw',

    // Fallback <img src> width target. Fixed, for browsers that do not
    // match any <source>. Glide will pick the closest available.
    'fallback_width' => 828,

    'formats' => [
        'avif'     => ['enabled' => true, 'quality' => 50],
        'webp'     => ['enabled' => true, 'quality' => 75],
        'fallback' => ['quality' => 82],
    ],

    'placeholder' => [
        'enabled' => true,
        'width'   => 32,
        'blur'    => 40,
        'quality' => 40,
    ],

    'glide' => [
        'default_fit' => 'crop_focal',
    ],

    'cache' => [
        'store'  => null,
        'ttl'    => null,
        'prefix' => 'respimg',
    ],
];
```

- [ ] **Step 2: Commit**

```bash
git add config/responsive-images.php
git commit -m "feat(responsive-images): add default config"
```

---

## Task 3: SrcsetBuilder (pure, TDD)

**Files:**
- Create: `tests/Unit/SrcsetBuilderTest.php`
- Create: `src/Image/SrcsetBuilder.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\Image\SrcsetBuilder;

class SrcsetBuilderTest extends TestCase
{
    private function config(): array
    {
        return [
            'device_sizes' => [640, 750, 828, 1080, 1200, 1920, 2048, 3840],
            'image_sizes'  => [16, 32, 48, 64, 96, 128, 256, 384],
        ];
    }

    public function test_combines_and_sorts_pools_capped_at_source_width(): void
    {
        $builder = new SrcsetBuilder();

        $widths = $builder->build(sourceWidth: 1500, config: $this->config());

        $this->assertSame(
            [16, 32, 48, 64, 96, 128, 256, 384, 640, 750, 828, 1080, 1200],
            $widths
        );
    }

    public function test_never_upscales_past_source_width(): void
    {
        $builder = new SrcsetBuilder();

        $widths = $builder->build(sourceWidth: 500, config: $this->config());

        foreach ($widths as $w) {
            $this->assertLessThanOrEqual(500, $w);
        }
    }

    public function test_override_widths_still_capped(): void
    {
        $builder = new SrcsetBuilder();

        $widths = $builder->build(
            sourceWidth: 1000,
            config: $this->config(),
            override: [400, 800, 1600, 3200]
        );

        $this->assertSame([400, 800, 1000], $widths);
    }

    public function test_override_widths_deduped_and_sorted(): void
    {
        $builder = new SrcsetBuilder();

        $widths = $builder->build(
            sourceWidth: 5000,
            config: $this->config(),
            override: [800, 400, 800, 1200]
        );

        $this->assertSame([400, 800, 1200], $widths);
    }

    public function test_empty_result_when_source_smaller_than_smallest(): void
    {
        $builder = new SrcsetBuilder();

        $widths = $builder->build(sourceWidth: 8, config: $this->config());

        $this->assertSame([8], $widths);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter SrcsetBuilderTest
```

Expected: FAIL with "Class Massif\ResponsiveImages\Image\SrcsetBuilder not found".

- [ ] **Step 3: Implement SrcsetBuilder**

```php
<?php

namespace Massif\ResponsiveImages\Image;

class SrcsetBuilder
{
    /**
     * @param  array<string, mixed>  $config
     * @param  int[]|null  $override
     * @return int[]
     */
    public function build(int $sourceWidth, array $config, ?array $override = null): array
    {
        $pool = $override ?? array_merge(
            $config['image_sizes'] ?? [],
            $config['device_sizes'] ?? []
        );

        $pool = array_values(array_unique(array_map('intval', $pool)));
        sort($pool);

        $widths = array_values(array_filter($pool, fn (int $w) => $w <= $sourceWidth));

        if ($widths === []) {
            return [$sourceWidth];
        }

        if (end($widths) < $sourceWidth && $sourceWidth < min($pool)) {
            $widths[] = $sourceWidth;
        }

        return $widths;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/phpunit --filter SrcsetBuilderTest
```

Expected: 5 tests, 5 assertions, OK.

- [ ] **Step 5: Commit**

```bash
git add src/Image/SrcsetBuilder.php \
        tests/Unit/SrcsetBuilderTest.php
git commit -m "feat(responsive-images): add SrcsetBuilder"
```

---

## Task 4: ResolvedImage DTO

**Files:**
- Create: `src/Image/ResolvedImage.php`

Tiny value object used by several later classes. No tests — it's a pure data holder.

- [ ] **Step 1: Create the class**

```php
<?php

namespace Massif\ResponsiveImages\Image;

use Statamic\Contracts\Assets\Asset;

final class ResolvedImage
{
    public function __construct(
        public readonly ?Asset $asset,
        public readonly string $id,
        public readonly int $mtime,
        public readonly ?string $url = null,
    ) {
    }

    public function isAsset(): bool
    {
        return $this->asset !== null;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Image/ResolvedImage.php
git commit -m "feat(responsive-images): add ResolvedImage DTO"
```

---

## Task 5: UrlBuilder (TDD)

**Files:**
- Create: `tests/Unit/UrlBuilderTest.php`
- Create: `src/Image/UrlBuilder.php`

`UrlBuilder` wraps Statamic's Glide URL helper. The helper returns something like `/img/asset/{id}?w=...&fm=webp&q=75`. In tests we replace the underlying `Statamic\Facades\Image` call with an injected closure so unit tests don't need a running Statamic container.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\Image\UrlBuilder;
use Massif\ResponsiveImages\Image\ResolvedImage;

class UrlBuilderTest extends TestCase
{
    private function image(): ResolvedImage
    {
        return new ResolvedImage(asset: null, id: 'fake-id', mtime: 1234, url: '/original.jpg');
    }

    private function stub(): UrlBuilder
    {
        return new UrlBuilder(
            urlFactory: function (ResolvedImage $image, array $params) {
                ksort($params);
                return '/img/'.$image->id.'?'.http_build_query($params);
            }
        );
    }

    public function test_builds_webp_url_with_width_and_quality(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'webp', quality: 75);

        $this->assertSame('/img/fake-id?fm=webp&q=75&w=800', $url);
    }

    public function test_includes_height_and_fit_when_ratio_active(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, height: 450, format: 'avif', quality: 50, fit: 'crop_focal');

        $this->assertSame('/img/fake-id?fit=crop_focal&fm=avif&h=450&q=50&w=800', $url);
    }

    public function test_fallback_format_omits_fm(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 828, format: 'fallback', quality: 82);

        $this->assertSame('/img/fake-id?q=82&w=828', $url);
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
vendor/bin/phpunit --filter UrlBuilderTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement UrlBuilder**

```php
<?php

namespace Massif\ResponsiveImages\Image;

use Closure;
use Statamic\Facades\Image as Glide;

class UrlBuilder
{
    /** @var Closure|null */
    private $urlFactory;

    public function __construct(?Closure $urlFactory = null)
    {
        $this->urlFactory = $urlFactory;
    }

    public function build(
        ResolvedImage $image,
        int $width,
        string $format,
        int $quality,
        ?int $height = null,
        ?string $fit = null,
    ): string {
        $params = ['w' => $width, 'q' => $quality];

        if ($height !== null) {
            $params['h'] = $height;
        }

        if ($fit !== null) {
            $params['fit'] = $fit;
        }

        if ($format !== 'fallback') {
            $params['fm'] = $format;
        }

        if ($this->urlFactory) {
            return ($this->urlFactory)($image, $params);
        }

        $manipulator = $image->isAsset()
            ? Glide::manipulate($image->asset)
            : Glide::manipulate($image->url);

        foreach ($params as $key => $value) {
            $manipulator->$key($value);
        }

        return $manipulator->build();
    }
}
```

- [ ] **Step 4: Run to verify pass**

```bash
vendor/bin/phpunit --filter UrlBuilderTest
```

Expected: 3 tests, 3 assertions, OK.

- [ ] **Step 5: Commit**

```bash
git add src/Image/UrlBuilder.php \
        tests/Unit/UrlBuilderTest.php
git commit -m "feat(responsive-images): add UrlBuilder"
```

---

## Task 6: MetadataReader + Metadata (cached, TDD)

**Files:**
- Create: `src/Image/MetadataReader.php`
- Create: `src/Image/Metadata.php`
- Create: `tests/Unit/MetadataTest.php`

`Metadata` is the cached wrapper. `MetadataReader` is the thing that actually reads pixels and is injected, so tests can use a fake.

- [ ] **Step 1: Write failing test**

```php
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
        $calls = 0;
        $reader = new class($calls) extends MetadataReader {
            public function __construct(private int &$calls) {}
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
        $this->assertSame(1, $calls);
    }

    public function test_new_mtime_invalidates_cache(): void
    {
        $calls = 0;
        $reader = new class($calls) extends MetadataReader {
            public function __construct(private int &$calls) {}
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

        $this->assertSame(2, $calls);
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
vendor/bin/phpunit --filter MetadataTest
```

Expected: FAIL — classes not found.

- [ ] **Step 3: Implement MetadataReader**

```php
<?php

namespace Massif\ResponsiveImages\Image;

class MetadataReader
{
    /**
     * @return array{width: int, height: int, mime: string}
     */
    public function read(ResolvedImage $image): array
    {
        if ($image->isAsset() && $image->asset !== null) {
            return [
                'width'  => (int) $image->asset->width(),
                'height' => (int) $image->asset->height(),
                'mime'   => (string) $image->asset->mimeType(),
            ];
        }

        $info = @getimagesize((string) $image->url);
        if ($info === false) {
            return ['width' => 0, 'height' => 0, 'mime' => 'application/octet-stream'];
        }

        return [
            'width'  => (int) $info[0],
            'height' => (int) $info[1],
            'mime'   => (string) $info['mime'],
        ];
    }
}
```

- [ ] **Step 4: Implement Metadata**

```php
<?php

namespace Massif\ResponsiveImages\Image;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

class Metadata
{
    public function __construct(
        private MetadataReader $reader,
        private CacheRepository $cache,
        private string $prefix = 'respimg',
        private ?int $ttl = null,
    ) {
    }

    /**
     * @return array{width: int, height: int, mime: string}
     */
    public function for(ResolvedImage $image): array
    {
        $key = sprintf('%s:meta:%s:%d', $this->prefix, $image->id, $image->mtime);

        $callback = fn () => $this->reader->read($image);

        return $this->ttl === null
            ? $this->cache->rememberForever($key, $callback)
            : $this->cache->remember($key, $this->ttl, $callback);
    }
}
```

- [ ] **Step 5: Run to verify pass**

```bash
vendor/bin/phpunit --filter MetadataTest
```

Expected: 2 tests, OK.

- [ ] **Step 6: Commit**

```bash
git add src/Image/Metadata.php \
        src/Image/MetadataReader.php \
        tests/Unit/MetadataTest.php
git commit -m "feat(responsive-images): add cached Metadata"
```

---

## Task 7: Placeholder (TDD)

**Files:**
- Create: `tests/Unit/PlaceholderTest.php`
- Create: `src/Image/Placeholder.php`

`Placeholder` takes a `ResolvedImage` + config and returns a base64 data URI. It needs to fetch the tiny blurred image once per asset. In tests we inject a fetcher closure so we don't need a running Glide.

- [ ] **Step 1: Write failing test**

```php
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
```

- [ ] **Step 2: Run to verify failure**

```bash
vendor/bin/phpunit --filter PlaceholderTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement Placeholder**

```php
<?php

namespace Massif\ResponsiveImages\Image;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Statamic\Facades\Image as Glide;

class Placeholder
{
    /** @var Closure|null */
    private $fetcher;

    public function __construct(
        private CacheRepository $cache,
        ?Closure $fetcher = null,
    ) {
        $this->fetcher = $fetcher;
    }

    public function dataUri(ResolvedImage $image, array $config): ?string
    {
        $cfg = $config['placeholder'] ?? [];
        if (empty($cfg['enabled'])) {
            return null;
        }

        $prefix = $config['cache']['prefix'] ?? 'respimg';
        $ttl    = $config['cache']['ttl'] ?? null;
        $key    = sprintf('%s:lqip:%s:%d', $prefix, $image->id, $image->mtime);

        $callback = fn () => $this->buildDataUri($image, $cfg);

        return $ttl === null
            ? $this->cache->rememberForever($key, $callback)
            : $this->cache->remember($key, $ttl, $callback);
    }

    private function buildDataUri(ResolvedImage $image, array $cfg): string
    {
        $payload = $this->fetcher
            ? ($this->fetcher)($image, $cfg)
            : $this->fetchViaGlide($image, $cfg);

        return sprintf(
            'data:%s;base64,%s',
            $payload['mime'] ?? 'image/jpeg',
            base64_encode($payload['bytes'] ?? '')
        );
    }

    private function fetchViaGlide(ResolvedImage $image, array $cfg): array
    {
        $manipulator = $image->isAsset()
            ? Glide::manipulate($image->asset)
            : Glide::manipulate($image->url);

        $url = $manipulator
            ->w((int) ($cfg['width'] ?? 32))
            ->blur((int) ($cfg['blur'] ?? 40))
            ->q((int) ($cfg['quality'] ?? 40))
            ->build();

        $bytes = @file_get_contents(public_path(ltrim($url, '/'))) ?: '';

        return ['bytes' => $bytes, 'mime' => 'image/jpeg'];
    }
}
```

- [ ] **Step 4: Run to verify pass**

```bash
vendor/bin/phpunit --filter PlaceholderTest
```

Expected: 2 tests, OK.

- [ ] **Step 5: Commit**

```bash
git add src/Image/Placeholder.php \
        tests/Unit/PlaceholderTest.php
git commit -m "feat(responsive-images): add Placeholder"
```

---

## Task 8: ImageResolver (TDD)

**Files:**
- Create: `tests/Unit/ImageResolverTest.php`
- Create: `src/Image/ImageResolver.php`

`ImageResolver` turns whatever the user passed as `:src` into a `ResolvedImage`. Supports: a Statamic `Asset` object, an asset reference string (`assets::id`), or a plain URL/path. Returns `null` on failure (caller logs).

- [ ] **Step 1: Write failing test**

```php
<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\Image\ImageResolver;

class ImageResolverTest extends TestCase
{
    public function test_resolves_url_string(): void
    {
        $resolver = new ImageResolver(
            assetLookup: fn () => null,
        );

        $resolved = $resolver->resolve('/uploads/photo.jpg');

        $this->assertNotNull($resolved);
        $this->assertFalse($resolved->isAsset());
        $this->assertSame('/uploads/photo.jpg', $resolved->url);
        $this->assertSame(md5('/uploads/photo.jpg'), $resolved->id);
    }

    public function test_returns_null_for_empty(): void
    {
        $resolver = new ImageResolver(assetLookup: fn () => null);

        $this->assertNull($resolver->resolve(''));
        $this->assertNull($resolver->resolve(null));
    }

    public function test_resolves_asset_reference(): void
    {
        $fakeAsset = new class {
            public function id() { return 'main::photo.jpg'; }
            public function lastModified() { return new \DateTime('@1700000000'); }
            public function url() { return '/assets/main/photo.jpg'; }
        };

        $resolver = new ImageResolver(
            assetLookup: fn (string $ref) => $ref === 'assets::main::photo.jpg' ? $fakeAsset : null,
        );

        $resolved = $resolver->resolve('assets::main::photo.jpg');

        $this->assertNotNull($resolved);
        $this->assertSame('main::photo.jpg', $resolved->id);
        $this->assertSame(1700000000, $resolved->mtime);
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
vendor/bin/phpunit --filter ImageResolverTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement ImageResolver**

```php
<?php

namespace Massif\ResponsiveImages\Image;

use Closure;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Asset as AssetFacade;

class ImageResolver
{
    /** @var Closure|null */
    private $assetLookup;

    public function __construct(?Closure $assetLookup = null)
    {
        $this->assetLookup = $assetLookup;
    }

    public function resolve(mixed $src): ?ResolvedImage
    {
        if ($src === null || $src === '') {
            return null;
        }

        if ($src instanceof Asset) {
            return $this->fromAsset($src);
        }

        if (is_object($src) && method_exists($src, 'id') && method_exists($src, 'url')) {
            return $this->fromAsset($src);
        }

        if (is_string($src) && str_starts_with($src, 'assets::')) {
            $asset = $this->lookupAsset($src);
            if ($asset !== null) {
                return $this->fromAsset($asset);
            }
            return null;
        }

        if (is_string($src)) {
            return new ResolvedImage(
                asset: null,
                id:    md5($src),
                mtime: 0,
                url:   $src,
            );
        }

        return null;
    }

    private function fromAsset(object $asset): ResolvedImage
    {
        $mtime = 0;
        if (method_exists($asset, 'lastModified')) {
            $last = $asset->lastModified();
            $mtime = $last instanceof \DateTimeInterface ? $last->getTimestamp() : (int) $last;
        }

        return new ResolvedImage(
            asset: $asset instanceof Asset ? $asset : null,
            id:    (string) $asset->id(),
            mtime: $mtime,
            url:   (string) $asset->url(),
        );
    }

    private function lookupAsset(string $ref): ?object
    {
        if ($this->assetLookup) {
            return ($this->assetLookup)($ref);
        }

        return AssetFacade::find(substr($ref, strlen('assets::')));
    }
}
```

- [ ] **Step 4: Run to verify pass**

```bash
vendor/bin/phpunit --filter ImageResolverTest
```

Expected: 3 tests, OK.

- [ ] **Step 5: Commit**

```bash
git add src/Image/ImageResolver.php \
        tests/Unit/ImageResolverTest.php
git commit -m "feat(responsive-images): add ImageResolver"
```

---

## Task 9: PictureRenderer (TDD)

**Files:**
- Create: `tests/Unit/PictureRendererTest.php`
- Create: `src/View/PictureRenderer.php`

`PictureRenderer` is a dumb string builder. It takes a fully-resolved data structure and emits HTML. Everything is already computed by callers.

Input shape:

```php
[
    'sources' => [
        ['type' => 'image/avif', 'srcset' => 'a 400w, b 800w', 'sizes' => '100vw', 'media' => null],
        ['type' => 'image/webp', 'srcset' => 'c 400w, d 800w', 'sizes' => '100vw', 'media' => null],
    ],
    'img' => [
        'src'    => '/img/...&w=828',
        'srcset' => 'x 400w, y 800w',
        'sizes'  => '100vw',
        'width'  => 1200,
        'height' => 675,
        'alt'    => 'caption',
        'class'  => null,
        'loading' => 'lazy',
        'decoding' => 'async',
        'fetchpriority' => 'auto',
        'placeholder' => 'data:image/jpeg;base64,AAA',
    ],
    'wrapper' => [
        'figure'        => false,
        'ratio_wrapper' => false,
        'ratio'         => null,
        'caption'       => null,
        'class'         => null,
    ],
]
```

- [ ] **Step 1: Write failing test**

```php
<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\View\PictureRenderer;

class PictureRendererTest extends TestCase
{
    private function baseData(): array
    {
        return [
            'sources' => [
                ['type' => 'image/avif', 'srcset' => 'a 400w', 'sizes' => '100vw', 'media' => null],
                ['type' => 'image/webp', 'srcset' => 'b 400w', 'sizes' => '100vw', 'media' => null],
            ],
            'img' => [
                'src' => '/i.jpg',
                'srcset' => 'c 400w',
                'sizes' => '100vw',
                'width' => 800,
                'height' => 450,
                'alt' => 'hello',
                'class' => null,
                'loading' => 'lazy',
                'decoding' => 'async',
                'fetchpriority' => 'auto',
                'placeholder' => null,
            ],
            'wrapper' => [
                'figure' => false,
                'ratio_wrapper' => false,
                'ratio' => null,
                'caption' => null,
                'class' => null,
            ],
        ];
    }

    public function test_basic_picture_output(): void
    {
        $html = (new PictureRenderer())->render($this->baseData());

        $this->assertStringContainsString('<picture>', $html);
        $this->assertStringContainsString('<source type="image/avif" srcset="a 400w" sizes="100vw">', $html);
        $this->assertStringContainsString('<source type="image/webp" srcset="b 400w" sizes="100vw">', $html);
        $this->assertStringContainsString('src="/i.jpg"', $html);
        $this->assertStringContainsString('width="800"', $html);
        $this->assertStringContainsString('height="450"', $html);
        $this->assertStringContainsString('alt="hello"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    public function test_escapes_user_strings(): void
    {
        $data = $this->baseData();
        $data['img']['alt'] = '"><script>x</script>';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;x&lt;/script&gt;', $html);
    }

    public function test_placeholder_style_inlined_on_img(): void
    {
        $data = $this->baseData();
        $data['img']['placeholder'] = 'data:image/jpeg;base64,AAA';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString(
            "style=\"background-size:cover;background-image:url('data:image/jpeg;base64,AAA')\"",
            $html
        );
    }

    public function test_ratio_wrapper(): void
    {
        $data = $this->baseData();
        $data['wrapper']['ratio_wrapper'] = true;
        $data['wrapper']['ratio'] = '16/9';
        $data['wrapper']['class'] = 'rounded';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString('<div style="aspect-ratio:16/9" class="rounded">', $html);
        $this->assertStringContainsString('</div>', $html);
    }

    public function test_figure_wrapper_with_caption(): void
    {
        $data = $this->baseData();
        $data['wrapper']['figure'] = true;
        $data['wrapper']['caption'] = 'A photo';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString('<figure>', $html);
        $this->assertStringContainsString('<figcaption>A photo</figcaption>', $html);
    }

    public function test_art_direction_media_query(): void
    {
        $data = $this->baseData();
        $data['sources'] = [
            ['type' => 'image/webp', 'srcset' => 'm 400w', 'sizes' => '100vw', 'media' => '(max-width: 768px)'],
            ['type' => 'image/webp', 'srcset' => 'd 400w', 'sizes' => '100vw', 'media' => null],
        ];

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString('media="(max-width: 768px)"', $html);
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
vendor/bin/phpunit --filter PictureRendererTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement PictureRenderer**

```php
<?php

namespace Massif\ResponsiveImages\View;

class PictureRenderer
{
    public function render(array $data): string
    {
        $picture = $this->renderPicture($data['sources'], $data['img']);

        $wrapper = $data['wrapper'];

        if ($wrapper['ratio_wrapper'] && $wrapper['ratio']) {
            $wrapperClass = $wrapper['figure'] ? null : $wrapper['class'];
            $picture = sprintf(
                '<div style="aspect-ratio:%s"%s>%s</div>',
                $this->e($wrapper['ratio']),
                $wrapperClass ? ' class="'.$this->e($wrapperClass).'"' : '',
                $picture
            );
        }

        if ($wrapper['figure']) {
            $classAttr = $wrapper['class'] ? ' class="'.$this->e($wrapper['class']).'"' : '';
            $caption = $wrapper['caption']
                ? '<figcaption>'.$this->e($wrapper['caption']).'</figcaption>'
                : '';
            return "<figure{$classAttr}>{$picture}{$caption}</figure>";
        }

        return $picture;
    }

    private function renderPicture(array $sources, array $img): string
    {
        $parts = ['<picture>'];

        foreach ($sources as $s) {
            $attrs = sprintf(
                ' type="%s" srcset="%s" sizes="%s"',
                $this->e($s['type']),
                $this->e($s['srcset']),
                $this->e($s['sizes'])
            );
            if (!empty($s['media'])) {
                $attrs .= ' media="'.$this->e($s['media']).'"';
            }
            $parts[] = '<source'.$attrs.'>';
        }

        $parts[] = $this->renderImg($img);
        $parts[] = '</picture>';

        return implode('', $parts);
    }

    private function renderImg(array $img): string
    {
        $attrs = [
            'src'           => $img['src'],
            'srcset'        => $img['srcset'],
            'sizes'         => $img['sizes'],
            'width'         => (string) $img['width'],
            'height'        => (string) $img['height'],
            'alt'           => (string) $img['alt'],
            'loading'       => $img['loading'],
            'decoding'      => $img['decoding'],
            'fetchpriority' => $img['fetchpriority'],
        ];

        if (!empty($img['class'])) {
            $attrs['class'] = $img['class'];
        }

        if (!empty($img['placeholder'])) {
            $attrs['style'] = "background-size:cover;background-image:url('".$img['placeholder']."')";
        }

        $rendered = '';
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === '') {
                if ($k !== 'alt') continue;
            }
            $rendered .= ' '.$k.'="'.($k === 'style' ? $v : $this->e((string) $v)).'"';
        }

        return '<img'.$rendered.'>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

- [ ] **Step 4: Run to verify pass**

```bash
vendor/bin/phpunit --filter PictureRendererTest
```

Expected: 6 tests, OK.

- [ ] **Step 5: Commit**

```bash
git add src/View/PictureRenderer.php \
        tests/Unit/PictureRendererTest.php
git commit -m "feat(responsive-images): add PictureRenderer"
```

---

## Task 10: ResponsiveImage Tag (integration, TDD)

**Files:**
- Create: `tests/Feature/ResponsiveImageTagTest.php`
- Create: `src/Tags/ResponsiveImage.php`
- Modify: `src/ServiceProvider.php` (uncomment tag registration if you stubbed it out in Task 1)

This task wires everything together. The tag class reads params, normalizes them, walks the pipeline from Task 3–9, and returns HTML via the renderer.

- [ ] **Step 1: Write failing test**

```php
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
    private function makeTag(array $config = []): ResponsiveImage
    {
        $cache = new Repository(new ArrayStore);

        $reader = new class extends MetadataReader {
            public function read(ResolvedImage $image): array
            {
                return ['width' => 1600, 'height' => 900, 'mime' => 'image/jpeg'];
            }
        };

        $resolver = new ImageResolver(assetLookup: fn () => null);
        $metadata = new Metadata($reader, $cache);
        $srcset   = new SrcsetBuilder();
        $urls     = new UrlBuilder(urlFactory: function ($img, $params) {
            ksort($params);
            return '/img/'.$img->id.'?'.http_build_query($params);
        });
        $placeholder = new Placeholder(
            cache: $cache,
            fetcher: fn () => ['bytes' => 'P', 'mime' => 'image/jpeg'],
        );

        $tag = new ResponsiveImage(
            $resolver, $metadata, $srcset, $urls, $placeholder,
            new PictureRenderer(),
            config: array_merge(require __DIR__.'/../../config/responsive-images.php', $config),
        );

        return $tag;
    }

    public function test_renders_picture_for_plain_url(): void
    {
        $tag = $this->makeTag();
        $tag->setParameters(['src' => '/uploads/photo.jpg', 'alt' => 'hi']);

        $html = $tag->index();

        $this->assertStringContainsString('<picture>', $html);
        $this->assertStringContainsString('type="image/avif"', $html);
        $this->assertStringContainsString('type="image/webp"', $html);
        $this->assertStringContainsString('alt="hi"', $html);
        $this->assertStringContainsString('width="1600"', $html);
    }

    public function test_ratio_forces_height_on_srcset(): void
    {
        $tag = $this->makeTag();
        $tag->setParameters(['src' => '/p.jpg', 'alt' => 'x', 'ratio' => '16/9']);

        $html = $tag->index();

        $this->assertStringContainsString('h=', $html);
        $this->assertStringContainsString('fit=crop_focal', $html);
    }

    public function test_empty_src_returns_empty_string(): void
    {
        $tag = $this->makeTag();
        $tag->setParameters(['src' => null, 'alt' => 'x']);

        $this->assertSame('', $tag->index());
    }

    public function test_disabling_avif_drops_avif_source(): void
    {
        $config = ['formats' => ['avif' => ['enabled' => false, 'quality' => 50]]];
        $tag = $this->makeTag($config);
        $tag->setParameters(['src' => '/p.jpg', 'alt' => 'x']);

        $html = $tag->index();

        $this->assertStringNotContainsString('image/avif', $html);
        $this->assertStringContainsString('image/webp', $html);
    }

    public function test_art_direction_sources(): void
    {
        $tag = $this->makeTag();
        $tag->setParameters([
            'src' => '/desk.jpg',
            'alt' => 'x',
            'sources' => [
                ['src' => '/mob.jpg',  'media' => '(max-width: 768px)'],
                ['src' => '/desk.jpg'],
            ],
        ]);

        $html = $tag->index();

        $this->assertStringContainsString('media="(max-width: 768px)"', $html);
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
vendor/bin/phpunit --filter ResponsiveImageTagTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement ResponsiveImage tag**

```php
<?php

namespace Massif\ResponsiveImages\Tags;

use Illuminate\Support\Facades\Log;
use Statamic\Tags\Tags;
use Massif\ResponsiveImages\Image\ImageResolver;
use Massif\ResponsiveImages\Image\Metadata;
use Massif\ResponsiveImages\Image\SrcsetBuilder;
use Massif\ResponsiveImages\Image\UrlBuilder;
use Massif\ResponsiveImages\Image\Placeholder;
use Massif\ResponsiveImages\Image\ResolvedImage;
use Massif\ResponsiveImages\View\PictureRenderer;

class ResponsiveImage extends Tags
{
    protected static $handle = 'responsive_image';

    public function __construct(
        private ?ImageResolver $resolver = null,
        private ?Metadata $metadata = null,
        private ?SrcsetBuilder $srcsetBuilder = null,
        private ?UrlBuilder $urlBuilder = null,
        private ?Placeholder $placeholder = null,
        private ?PictureRenderer $renderer = null,
        private ?array $config = null,
    ) {
        parent::__construct();

        $this->resolver      ??= app(ImageResolver::class);
        $this->metadata      ??= app(Metadata::class);
        $this->srcsetBuilder ??= app(SrcsetBuilder::class);
        $this->urlBuilder    ??= app(UrlBuilder::class);
        $this->placeholder   ??= app(Placeholder::class);
        $this->renderer      ??= app(PictureRenderer::class);
        $this->config        ??= config('responsive-images');
    }

    public function index(): string
    {
        $src = $this->params->get('src');
        $image = $this->resolver->resolve($src);

        if ($image === null) {
            Log::warning('[responsive_image] unresolvable src', ['src' => $src]);
            return '';
        }

        return $this->renderForImage($image, $this->params->all());
    }

    private function renderForImage(ResolvedImage $image, array $params): string
    {
        $meta = $this->metadata->for($image);
        $sourceWidth = (int) ($meta['width'] ?: 1920);

        $alt = $this->resolveAlt($params, $image);

        $ratio = $this->parseRatio($params['ratio'] ?? null);
        $fit = $params['fit'] ?? ($ratio ? ($this->config['glide']['default_fit'] ?? 'crop_focal') : null);

        $widthsOverride = $this->parseWidths($params['widths'] ?? null);
        $widths = $this->srcsetBuilder->build($sourceWidth, $this->config, $widthsOverride);

        [$imgWidth, $imgHeight] = $this->resolveDimensions($params, $meta, $ratio, $widths);

        $sizes = (string) ($params['sizes'] ?? $this->config['default_sizes']);

        $artDirection = $params['sources'] ?? null;

        if (is_array($artDirection) && $artDirection !== []) {
            $sources = $this->buildArtDirectionSources($artDirection, $sizes, $ratio, $fit);
            $fallbackImage = $this->resolver->resolve($artDirection[count($artDirection) - 1]['src'] ?? $image);
            $fallbackImage ??= $image;
        } else {
            $sources = $this->buildFormatSources($image, $widths, $sizes, $imgHeight, $fit);
            $fallbackImage = $image;
        }

        $imgSrc = $this->urlBuilder->build(
            $fallbackImage,
            width: (int) ($this->config['fallback_width'] ?? 828),
            format: 'fallback',
            quality: (int) ($this->config['formats']['fallback']['quality'] ?? 82),
            height: $ratio ? (int) round(($this->config['fallback_width'] ?? 828) / $ratio) : null,
            fit: $fit,
        );

        $fallbackSrcset = $this->buildSrcset($fallbackImage, $widths, 'fallback', $imgHeight, $fit);

        $placeholder = ($params['placeholder'] ?? null) === 'false'
            ? null
            : $this->placeholder->dataUri($fallbackImage, $this->config);

        $data = [
            'sources' => $sources,
            'img' => [
                'src'           => $imgSrc,
                'srcset'        => $fallbackSrcset,
                'sizes'         => $sizes,
                'width'         => $imgWidth,
                'height'        => $imgHeight,
                'alt'           => $alt,
                'class'         => $params['img_class'] ?? null,
                'loading'       => $params['loading'] ?? 'lazy',
                'decoding'      => $params['decoding'] ?? 'async',
                'fetchpriority' => $params['fetchpriority'] ?? 'auto',
                'placeholder'   => $placeholder,
            ],
            'wrapper' => [
                'figure'        => filter_var($params['figure'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'ratio_wrapper' => filter_var($params['ratio_wrapper'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'ratio'         => $ratio ? $this->formatRatioString($params['ratio']) : null,
                'caption'       => $params['caption'] ?? null,
                'class'         => $params['class'] ?? null,
            ],
        ];

        return $this->renderer->render($data);
    }

    private function buildFormatSources(ResolvedImage $image, array $widths, string $sizes, ?int $height, ?string $fit): array
    {
        $sources = [];
        foreach (['avif', 'webp'] as $format) {
            if (empty($this->config['formats'][$format]['enabled'])) {
                continue;
            }
            $sources[] = [
                'type'   => 'image/'.$format,
                'srcset' => $this->buildSrcset($image, $widths, $format, $height, $fit),
                'sizes'  => $sizes,
                'media'  => null,
            ];
        }
        return $sources;
    }

    private function buildArtDirectionSources(array $entries, string $defaultSizes, ?float $parentRatio, ?string $fit): array
    {
        $result = [];
        foreach (['avif', 'webp', 'fallback'] as $format) {
            if ($format !== 'fallback' && empty($this->config['formats'][$format]['enabled'])) {
                continue;
            }
            foreach ($entries as $entry) {
                $resolved = $this->resolver->resolve($entry['src'] ?? null);
                if ($resolved === null) continue;

                $meta = $this->metadata->for($resolved);
                $entryRatio = $this->parseRatio($entry['ratio'] ?? null) ?? $parentRatio;
                $widths = $this->srcsetBuilder->build((int) $meta['width'], $this->config);
                $height = $entryRatio
                    ? (int) round(end($widths) / $entryRatio)
                    : null;

                $result[] = [
                    'type'   => $format === 'fallback' ? 'image/'.explode('/', (string) $meta['mime'])[1] : 'image/'.$format,
                    'srcset' => $this->buildSrcset($resolved, $widths, $format, $height, $fit),
                    'sizes'  => (string) ($entry['sizes'] ?? $defaultSizes),
                    'media'  => $entry['media'] ?? null,
                ];
            }
        }
        return $result;
    }

    private function buildSrcset(ResolvedImage $image, array $widths, string $format, ?int $height, ?string $fit): string
    {
        $quality = (int) ($this->config['formats'][$format]['quality'] ?? 82);
        $parts = [];
        foreach ($widths as $w) {
            $h = $height !== null && $widths ? (int) round($w * ($height / max(end($widths), 1))) : null;
            $parts[] = $this->urlBuilder->build($image,
                width: $w, format: $format, quality: $quality, height: $h, fit: $fit,
            ).' '.$w.'w';
        }
        return implode(', ', $parts);
    }

    private function parseRatio(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') return null;
        $raw = str_replace(':', '/', (string) $raw);
        $parts = explode('/', $raw);
        if (count($parts) !== 2) return null;
        $a = (float) $parts[0];
        $b = (float) $parts[1];
        return $b > 0 ? $a / $b : null;
    }

    private function formatRatioString(mixed $raw): string
    {
        return str_replace(':', '/', (string) $raw);
    }

    private function parseWidths(mixed $raw): ?array
    {
        if (!$raw) return null;
        if (is_array($raw)) return array_map('intval', $raw);
        return array_map('intval', array_filter(array_map('trim', explode(',', (string) $raw))));
    }

    private function resolveDimensions(array $params, array $meta, ?float $ratio, array $widths): array
    {
        $w = isset($params['width']) ? (int) $params['width'] : null;
        $h = isset($params['height']) ? (int) $params['height'] : null;

        if ($w !== null && $h !== null) return [$w, $h];

        if ($ratio !== null) {
            $w ??= $widths ? (int) end($widths) : (int) $meta['width'];
            $h ??= (int) round($w / $ratio);
            return [$w, $h];
        }

        return [(int) $meta['width'], (int) $meta['height']];
    }

    private function resolveAlt(array $params, ResolvedImage $image): string
    {
        if (array_key_exists('alt', $params)) {
            $value = (string) $params['alt'];
            if ($value !== '') return $value;
        }

        if ($image->isAsset() && $image->asset !== null) {
            $assetAlt = method_exists($image->asset, 'get') ? (string) $image->asset->get('alt') : '';
            if ($assetAlt !== '') return $assetAlt;
        }

        Log::warning('[responsive_image] missing alt text', ['id' => $image->id]);
        return '';
    }
}
```

- [ ] **Step 4: Uncomment tag registration in ServiceProvider if it was stubbed out**

Ensure the `$tags` array in `src/ServiceProvider.php` lists `ResponsiveImage::class`.

- [ ] **Step 5: Run to verify pass**

```bash
vendor/bin/phpunit --filter ResponsiveImageTagTest
```

Expected: 5 tests, OK.

- [ ] **Step 6: Run full suite to catch regressions**

```bash
vendor/bin/phpunit
```

Expected: all unit + feature tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/Tags/ResponsiveImage.php \
        src/ServiceProvider.php \
        tests/Feature/ResponsiveImageTagTest.php
git commit -m "feat(responsive-images): wire ResponsiveImage tag"
```

---

## Task 11: Container Bindings

**Files:**
- Modify: `src/ServiceProvider.php`

Currently the tag falls back to `app(...)` for each collaborator but Laravel can't construct `Metadata` or `Placeholder` automatically (they need a cache repository and injected closures). Register them in the container.

- [ ] **Step 1: Update ServiceProvider `register()`**

```php
public function register(): void
{
    parent::register();

    $this->mergeConfigFrom(
        __DIR__ . '/../config/responsive-images.php',
        'responsive-images'
    );

    $this->app->singleton(\Massif\ResponsiveImages\Image\SrcsetBuilder::class);
    $this->app->singleton(\Massif\ResponsiveImages\Image\MetadataReader::class);
    $this->app->singleton(\Massif\ResponsiveImages\Image\ImageResolver::class, function () {
        return new \Massif\ResponsiveImages\Image\ImageResolver();
    });
    $this->app->singleton(\Massif\ResponsiveImages\Image\UrlBuilder::class, function () {
        return new \Massif\ResponsiveImages\Image\UrlBuilder();
    });
    $this->app->singleton(\Massif\ResponsiveImages\Image\Metadata::class, function ($app) {
        $config = $app['config']->get('responsive-images');
        $store  = $config['cache']['store'] ?? null;
        $ttl    = $config['cache']['ttl'] ?? null;
        $prefix = $config['cache']['prefix'] ?? 'respimg';

        return new \Massif\ResponsiveImages\Image\Metadata(
            $app->make(\Massif\ResponsiveImages\Image\MetadataReader::class),
            $app['cache']->store($store),
            $prefix,
            $ttl,
        );
    });
    $this->app->singleton(\Massif\ResponsiveImages\Image\Placeholder::class, function ($app) {
        $config = $app['config']->get('responsive-images');
        $store  = $config['cache']['store'] ?? null;

        return new \Massif\ResponsiveImages\Image\Placeholder(
            $app['cache']->store($store),
        );
    });
    $this->app->singleton(\Massif\ResponsiveImages\View\PictureRenderer::class);
}
```

- [ ] **Step 2: Run full suite**

```bash
vendor/bin/phpunit
```

Expected: all green.

- [ ] **Step 3: Commit**

```bash
git add src/ServiceProvider.php
git commit -m "feat(responsive-images): register container bindings"
```

---

## Task 12: README

**Files:**
- Create: `README.md`

- [ ] **Step 1: Write README**

```markdown
# Responsive Images for Statamic 6

A single Antlers tag that emits a `<picture>` element with AVIF, WebP, and fallback sources, CLS-safe dimensions, inline blurred LQIP, and Next.js-inspired srcset generation. All image encoding is delegated to Statamic's Glide pipeline and cached to disk.

## Installation

```bash
composer require massif/responsive-images
php artisan vendor:publish --tag=responsive-images-config
```

## Quickstart

```antlers
{{ responsive_image :src="image" alt="A descriptive caption" }}
```

That's it. You get a `<picture>` with AVIF + WebP + original-format sources, an auto-generated srcset based on the source image's intrinsic width, correct `width` and `height` attributes to prevent CLS, and a blurred placeholder.

## Parameters

| Param | Required | Default | Description |
|---|---|---|---|
| `src` | yes | — | Statamic `Asset`, `assets::id` reference, or URL/path |
| `alt` | effectively | asset meta `alt` | Param overrides for this render only; does not mutate the asset |
| `sizes` | no | `"100vw"` | `sizes` attribute passed to `<img>` and `<source>` |
| `class` | no | — | Applied to outermost wrapper |
| `img_class` | no | — | Always applied to `<img>` |
| `loading` | no | `lazy` | |
| `decoding` | no | `async` | |
| `fetchpriority` | no | `auto` | |
| `ratio` | no | — | `"16/9"` or `"16:9"` |
| `fit` | no | `crop_focal` when ratio active, `contain` otherwise | Glide fit mode |
| `width` / `height` | no | derived | Explicit intrinsic overrides |
| `figure` | no | `false` | Wraps in `<figure>` |
| `caption` | no | — | Populates `<figcaption>` when `figure=true` |
| `ratio_wrapper` | no | `false` | Wraps `<picture>` in `<div style="aspect-ratio:X/Y">` |
| `placeholder` | no | config default | Per-call override |
| `:sources` | no | — | Antlers array for art direction |
| `widths` | no | derived | Escape hatch, comma-separated or array |

## Art Direction

```antlers
{{ responsive_image
    :src="hero_desktop"
    alt="..."
    :sources="[
        { src: hero_mobile, media: '(max-width: 768px)' },
        { src: hero_desktop }
    ]"
/}}
```

## Configuration

See `config/responsive-images.php`. Notable keys:

- `device_sizes` / `image_sizes` — srcset pool (next/image model)
- `formats.avif.enabled` / `formats.webp.enabled` — format toggles
- `placeholder.enabled` — LQIP on/off
- `fallback_width` — width used for `<img src>` fallback
- `cache.store` / `cache.ttl` — metadata and placeholder cache

## Performance Notes

- **Zero image encoding per request.** `UrlBuilder` only builds Glide URLs; Statamic's Glide controller encodes on the first browser hit, then caches to disk forever.
- **One metadata read per asset, ever.** Cached by `{id}:{mtime}`, invalidated on asset replacement.
- **One placeholder generation per asset, ever.** Same caching.
- **Steady-state render is pure array + string work.** No I/O in the hot path.

## Caveats

- AVIF requires Imagick with AVIF support or GD ≥ 8.1 with libavif. If your Glide driver can't encode AVIF, disable it in config.
- The inline base64 placeholder adds ~1-2KB per image to your HTML. If that's a concern, reduce `placeholder.width` or disable.
- Cold-cache placeholder generation performs one synchronous Glide fetch. First render of a new asset will be slightly slower.
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs(responsive-images): add README"
```

---

## Self-Review

**Spec coverage walkthrough:**

- Addon structure → Task 1, 11, 12.
- Tag API (all params, defaults, required/optional) → Task 10, documented in Task 12.
- Config file → Task 2.
- Srcset generation (device_sizes ∪ image_sizes, capped) → Task 3.
- Dimension resolution (explicit / ratio-derived / intrinsic) → Task 10 `resolveDimensions`.
- Data flow (resolver → metadata → srcset → url → render) → Task 10.
- Caching guarantees → Task 6 (Metadata), Task 7 (Placeholder), Task 11 (container wiring for store).
- Rendering rules (standard, ratio_wrapper, figure, art direction, placeholder inlining, escaping) → Task 9.
- Fallback `<img src>` fixed at `fallback_width` in original format → Task 10 `imgSrc`.
- Security (htmlspecialchars) → Task 9 `e()`.
- Testing strategy (per-class unit tests + feature tests) → Tasks 3–10.

**Placeholder scan:** None.

**Type consistency:** Checked. `ResolvedImage` fields, `SrcsetBuilder::build` signature, `UrlBuilder::build` named args, `Metadata::for` shape, and `PictureRenderer::render` input shape are consistent across tasks.
