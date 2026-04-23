# Improve With Peak — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the `Massif\ResponsiveImages` Statamic 6 addon with a short `pic` alias, wildcard form (`{{ tag:field }}`), SVG/GIF passthrough, array-src unwrap, `<link rel="preload">` support, Glide passthrough knobs (`quality`, `formats`, `blur`, `sharpen`, etc.), focal-point CSS variable, `aria-hidden` on empty alt, and a layout-aware default `sizes` — adopted from Peak's `Picture` tag without regressing Massif's existing correctness.

**Architecture:** Targeted extraction. Two new classes (`View/Preloader`, `View/PassthroughRenderer`) own genuinely separate concerns (stack push; bare-img rendering for SVG/GIF/failed-meta). One new subclass (`Tags/Pic`) provides the short alias. Existing classes (`ResponsiveImage` tag, `ImageResolver`, `UrlBuilder`, `PictureRenderer`, `ServiceProvider`) gain param-level or signature-level additions. No new abstractions beyond what the features require.

**Tech Stack:** PHP 8.2+, Statamic 6, Laravel 12, PHPUnit (via `orchestra/testbench`), Statamic Glide pipeline.

**Spec:** `docs/superpowers/specs/2026-04-17-improve-with-peak-design.md`

**Test command:** `./vendor/bin/phpunit` (run from repo root). Single file: `./vendor/bin/phpunit tests/Unit/XxxTest.php`.

---

## File Map

**Create:**
- `src/Tags/Pic.php`
- `src/View/Preloader.php`
- `src/View/PassthroughRenderer.php`
- `tests/Unit/PreloaderTest.php`
- `tests/Unit/PassthroughRendererTest.php`
- `tests/Unit/UrlBuilderExtrasTest.php`

**Modify:**
- `src/Tags/ResponsiveImage.php`
- `src/Image/ImageResolver.php`
- `src/Image/UrlBuilder.php`
- `src/View/PictureRenderer.php`
- `src/ServiceProvider.php`
- `config/responsive-images.php`
- `tests/Unit/ImageResolverTest.php`
- `tests/Unit/PictureRendererTest.php`
- `tests/Feature/ResponsiveImageTagTest.php`
- `README.md`
- `CHANGELOG.md`

---

## Task 1: ImageResolver — array unwrap

**Files:**
- Modify: `src/Image/ImageResolver.php`
- Test: `tests/Unit/ImageResolverTest.php`

- [ ] **Step 1.1: Add failing test for array unwrap with single element**

Append to `tests/Unit/ImageResolverTest.php` (before the final `}`):

```php
    public function test_unwraps_array_with_single_asset(): void
    {
        $fakeAsset = new class {
            public function id() { return 'main::photo.jpg'; }
            public function lastModified() { return new \DateTime('@1700000000'); }
            public function url() { return '/assets/main/photo.jpg'; }
        };

        $resolver = new ImageResolver(assetLookup: fn () => null);

        $resolved = $resolver->resolve([$fakeAsset]);

        $this->assertNotNull($resolved);
        $this->assertSame('main::photo.jpg', $resolved->id);
    }

    public function test_unwraps_array_with_url_string(): void
    {
        $resolver = new ImageResolver(assetLookup: fn () => null);

        $resolved = $resolver->resolve(['/uploads/photo.jpg']);

        $this->assertNotNull($resolved);
        $this->assertFalse($resolved->isAsset());
        $this->assertSame('/uploads/photo.jpg', $resolved->url);
    }

    public function test_empty_array_returns_null(): void
    {
        $resolver = new ImageResolver(assetLookup: fn () => null);

        $this->assertNull($resolver->resolve([]));
    }

    public function test_array_with_null_returns_null(): void
    {
        $resolver = new ImageResolver(assetLookup: fn () => null);

        $this->assertNull($resolver->resolve([null]));
    }
```

- [ ] **Step 1.2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Unit/ImageResolverTest.php`
Expected: 4 new tests fail (`resolve()` returns null for arrays today).

- [ ] **Step 1.3: Implement array unwrap in resolver**

In `src/Image/ImageResolver.php`, inside `resolve(mixed $src): ?ResolvedImage`, insert the array branch immediately after the `null`/empty-string guard and before the `Asset` check:

```php
        if (is_array($src)) {
            if ($src === []) {
                return null;
            }
            return $this->resolve($src[0]);
        }
```

Final `resolve()` order of checks:
1. null / empty string → null
2. array → recurse on first element (or return null for empty)
3. Asset instance → `fromAsset`
4. asset-like object (has `id()` and `url()`) → `fromAsset`
5. `assets::...` string → `lookupAsset`
6. any other string → URL ResolvedImage
7. else → null

- [ ] **Step 1.4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/ImageResolverTest.php`
Expected: all tests pass, no regressions.

- [ ] **Step 1.5: Commit**

```bash
git add src/Image/ImageResolver.php tests/Unit/ImageResolverTest.php
git commit -m "feat(responsive-images): unwrap array src in ImageResolver"
```

---

## Task 2: UrlBuilder — `$extras` passthrough with whitelist

**Files:**
- Modify: `src/Image/UrlBuilder.php`
- Create: `tests/Unit/UrlBuilderExtrasTest.php`

- [ ] **Step 2.1: Write failing tests for $extras passthrough**

Create `tests/Unit/UrlBuilderExtrasTest.php`:

```php
<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\Image\UrlBuilder;
use Massif\ResponsiveImages\Image\ResolvedImage;

class UrlBuilderExtrasTest extends TestCase
{
    private function image(): ResolvedImage
    {
        return new ResolvedImage(asset: null, id: 'x', mtime: 0, url: '/o.jpg');
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

    public function test_whitelisted_extras_pass_through(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'webp', quality: 75,
            extras: ['blur' => 10, 'sharpen' => 5]);

        $this->assertStringContainsString('blur=10', $url);
        $this->assertStringContainsString('sharpen=5', $url);
    }

    public function test_non_whitelisted_extras_are_dropped(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'webp', quality: 75,
            extras: ['dpr' => 2, 'bogus' => 'evil']);

        $this->assertStringNotContainsString('dpr=', $url);
        $this->assertStringNotContainsString('bogus=', $url);
    }

    public function test_non_numeric_values_dropped_for_numeric_keys(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'webp', quality: 75,
            extras: ['blur' => 'abc', 'sharpen' => '7']);

        $this->assertStringNotContainsString('blur=', $url);
        $this->assertStringContainsString('sharpen=7', $url);
    }

    public function test_extras_cannot_override_core_keys(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'webp', quality: 75,
            extras: ['w' => 9999, 'h' => 9999, 'q' => 1, 'fm' => 'jpg', 'fit' => 'stretch']);

        $this->assertStringContainsString('w=800', $url);
        $this->assertStringNotContainsString('w=9999', $url);
        $this->assertStringContainsString('q=75', $url);
        $this->assertStringNotContainsString('q=1', $url);
        $this->assertStringContainsString('fm=webp', $url);
    }

    public function test_string_passthrough_keys_trim(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'webp', quality: 75,
            extras: ['flip' => '  h  ', 'filter' => 'sepia']);

        $this->assertStringContainsString('flip=h', $url);
        $this->assertStringContainsString('filter=sepia', $url);
    }
}
```

- [ ] **Step 2.2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Unit/UrlBuilderExtrasTest.php`
Expected: all fail (unknown `extras:` named argument, method signature doesn't accept it).

- [ ] **Step 2.3: Extend UrlBuilder signature with whitelisted extras**

Replace the full `src/Image/UrlBuilder.php` body with:

```php
<?php

namespace Massif\ResponsiveImages\Image;

use Closure;
use Statamic\Facades\Image as Glide;

class UrlBuilder
{
    private const EXTRAS_NUMERIC = ['blur', 'brightness', 'contrast', 'gamma', 'pixelate', 'sharpen'];
    private const EXTRAS_STRING  = ['bg', 'filter', 'flip', 'orient'];

    /** @var Closure|null */
    private $urlFactory;

    public function __construct(?Closure $urlFactory = null)
    {
        $this->urlFactory = $urlFactory;
    }

    /**
     * @param  array<string, mixed>  $extras
     */
    public function build(
        ResolvedImage $image,
        int $width,
        string $format,
        int $quality,
        ?int $height = null,
        ?string $fit = null,
        array $extras = [],
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

        foreach ($this->filterExtras($extras) as $key => $value) {
            if (array_key_exists($key, $params)) {
                continue;
            }
            $params[$key] = $value;
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

    /**
     * @param  array<string, mixed>  $extras
     * @return array<string, int|string>
     */
    private function filterExtras(array $extras): array
    {
        $out = [];
        foreach ($extras as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (in_array($key, self::EXTRAS_NUMERIC, true)) {
                if (is_numeric($value)) {
                    $out[$key] = (int) $value;
                }
                continue;
            }
            if (in_array($key, self::EXTRAS_STRING, true)) {
                $trimmed = trim((string) $value);
                if ($trimmed !== '') {
                    $out[$key] = $trimmed;
                }
            }
        }
        return $out;
    }
}
```

- [ ] **Step 2.4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/UrlBuilderTest.php tests/Unit/UrlBuilderExtrasTest.php`
Expected: both files green. Existing 3 tests still pass (extras defaults to empty, behavior unchanged).

- [ ] **Step 2.5: Commit**

```bash
git add src/Image/UrlBuilder.php tests/Unit/UrlBuilderExtrasTest.php
git commit -m "feat(responsive-images): add whitelisted Glide passthrough extras to UrlBuilder"
```

---

## Task 3: PassthroughRenderer — bare `<img>` for SVG / GIF / failed meta

**Files:**
- Create: `src/View/PassthroughRenderer.php`
- Create: `tests/Unit/PassthroughRendererTest.php`

- [ ] **Step 3.1: Write failing unit tests**

Create `tests/Unit/PassthroughRendererTest.php`:

```php
<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\View\PassthroughRenderer;
use Massif\ResponsiveImages\Image\ResolvedImage;

class PassthroughRendererTest extends TestCase
{
    private function image(string $url = '/file.svg'): ResolvedImage
    {
        return new ResolvedImage(asset: null, id: 'id', mtime: 0, url: $url);
    }

    public function test_renders_bare_img_with_core_attrs(): void
    {
        $html = (new PassthroughRenderer())->render($this->image('/u/a.svg'), [
            'alt' => 'a logo',
        ]);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('src="/u/a.svg"', $html);
        $this->assertStringContainsString('alt="a logo"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('decoding="async"', $html);
        $this->assertStringNotContainsString('<picture', $html);
        $this->assertStringNotContainsString('srcset=', $html);
        $this->assertStringNotContainsString('sizes=', $html);
    }

    public function test_aria_hidden_when_alt_empty(): void
    {
        $html = (new PassthroughRenderer())->render($this->image(), [
            'alt' => '',
        ]);

        $this->assertStringContainsString('alt=""', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_no_aria_hidden_when_alt_present(): void
    {
        $html = (new PassthroughRenderer())->render($this->image(), [
            'alt' => 'x',
        ]);

        $this->assertStringNotContainsString('aria-hidden', $html);
    }

    public function test_includes_intrinsic_dimensions_when_provided(): void
    {
        $html = (new PassthroughRenderer())->render($this->image(), [
            'alt'    => 'x',
            'width'  => 320,
            'height' => 240,
        ]);

        $this->assertStringContainsString('width="320"', $html);
        $this->assertStringContainsString('height="240"', $html);
    }

    public function test_omits_dimensions_when_zero(): void
    {
        $html = (new PassthroughRenderer())->render($this->image(), [
            'alt'    => 'x',
            'width'  => 0,
            'height' => 0,
        ]);

        $this->assertStringNotContainsString('width=', $html);
        $this->assertStringNotContainsString('height=', $html);
    }

    public function test_honors_class_loading_decoding(): void
    {
        $html = (new PassthroughRenderer())->render($this->image(), [
            'alt'      => 'x',
            'class'    => 'hero',
            'loading'  => 'eager',
            'decoding' => 'sync',
        ]);

        $this->assertStringContainsString('class="hero"', $html);
        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringContainsString('decoding="sync"', $html);
    }

    public function test_escapes_html_in_attrs(): void
    {
        $html = (new PassthroughRenderer())->render($this->image('/u/x.svg'), [
            'alt' => '"><script>y</script>',
        ]);

        $this->assertStringNotContainsString('<script>y</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
```

- [ ] **Step 3.2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Unit/PassthroughRendererTest.php`
Expected: all fail (class does not exist).

- [ ] **Step 3.3: Implement PassthroughRenderer**

Create `src/View/PassthroughRenderer.php`:

```php
<?php

namespace Massif\ResponsiveImages\View;

use Massif\ResponsiveImages\Image\ResolvedImage;

class PassthroughRenderer
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function render(ResolvedImage $image, array $params): string
    {
        $src = $image->isAsset() && $image->asset !== null
            ? (string) $image->asset->url()
            : (string) $image->url;

        $alt      = (string) ($params['alt'] ?? '');
        $loading  = (string) ($params['loading'] ?? 'lazy');
        $decoding = (string) ($params['decoding'] ?? 'async');
        $class    = $params['class'] ?? null;
        $width    = (int) ($params['width'] ?? 0);
        $height   = (int) ($params['height'] ?? 0);

        $attrs = [
            'src'      => $src,
            'alt'      => $alt,
            'loading'  => $loading,
            'decoding' => $decoding,
        ];

        if ($width > 0) {
            $attrs['width'] = (string) $width;
        }
        if ($height > 0) {
            $attrs['height'] = (string) $height;
        }
        if (is_string($class) && $class !== '') {
            $attrs['class'] = $class;
        }
        if ($alt === '') {
            $attrs['aria-hidden'] = 'true';
        }

        $rendered = '';
        foreach ($attrs as $k => $v) {
            $rendered .= ' '.$k.'="'.$this->e((string) $v).'"';
        }

        return '<img'.$rendered.'>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

- [ ] **Step 3.4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/PassthroughRendererTest.php`
Expected: all pass.

- [ ] **Step 3.5: Commit**

```bash
git add src/View/PassthroughRenderer.php tests/Unit/PassthroughRendererTest.php
git commit -m "feat(responsive-images): add PassthroughRenderer for SVG/GIF/failed-meta"
```

---

## Task 4: Preloader — head-stack push

**Files:**
- Create: `src/View/Preloader.php`
- Create: `tests/Unit/PreloaderTest.php`

Note: The real implementation calls `\Statamic\View\Antlers\Language\Runtime\StackReplacementManager::pushStack()`, which requires Statamic runtime state. To keep Preloader unit-testable, the constructor accepts an optional `Closure $pusher` seam (defaults to the real push). Same pattern as `UrlBuilder::$urlFactory`.

- [ ] **Step 4.1: Write failing unit tests**

Create `tests/Unit/PreloaderTest.php`:

```php
<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\View\Preloader;

class PreloaderTest extends TestCase
{
    private array $captured = [];

    private function preloader(): Preloader
    {
        $this->captured = [];
        return new Preloader(pusher: function (string $stackName, string $content) {
            $this->captured[] = ['stack' => $stackName, 'content' => $content];
        });
    }

    public function test_push_emits_link_with_srcset_sizes_type(): void
    {
        $this->preloader()->push(
            srcset: '/img/a?w=400 400w, /img/a?w=800 800w',
            sizes: '100vw',
            mimeType: 'image/avif',
        );

        $this->assertCount(1, $this->captured);
        $this->assertSame('head', $this->captured[0]['stack']);

        $link = $this->captured[0]['content'];
        $this->assertStringContainsString('<link', $link);
        $this->assertStringContainsString('rel="preload"', $link);
        $this->assertStringContainsString('as="image"', $link);
        $this->assertStringContainsString('imagesrcset="/img/a?w=400 400w, /img/a?w=800 800w"', $link);
        $this->assertStringContainsString('imagesizes="100vw"', $link);
        $this->assertStringContainsString('type="image/avif"', $link);
        $this->assertStringContainsString('fetchpriority="high"', $link);
    }

    public function test_push_escapes_srcset_and_sizes(): void
    {
        $this->preloader()->push(
            srcset: '"><script>x</script>',
            sizes: '100vw',
            mimeType: 'image/webp',
        );

        $link = $this->captured[0]['content'];
        $this->assertStringNotContainsString('<script>x</script>', $link);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $link);
    }

    public function test_push_noops_when_srcset_empty(): void
    {
        $this->preloader()->push(srcset: '', sizes: '100vw', mimeType: 'image/avif');

        $this->assertSame([], $this->captured);
    }

    public function test_push_noops_when_mime_empty(): void
    {
        $this->preloader()->push(srcset: '/a 1w', sizes: '100vw', mimeType: '');

        $this->assertSame([], $this->captured);
    }
}
```

- [ ] **Step 4.2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Unit/PreloaderTest.php`
Expected: all fail (class does not exist).

- [ ] **Step 4.3: Implement Preloader**

Create `src/View/Preloader.php`:

```php
<?php

namespace Massif\ResponsiveImages\View;

use Closure;
use Statamic\View\Antlers\Language\Runtime\StackReplacementManager;

class Preloader
{
    /** @var Closure|null */
    private $pusher;

    public function __construct(?Closure $pusher = null)
    {
        $this->pusher = $pusher;
    }

    public function push(string $srcset, string $sizes, string $mimeType): void
    {
        if ($srcset === '' || $mimeType === '') {
            return;
        }

        $link = sprintf(
            '<link rel="preload" as="image" imagesrcset="%s" imagesizes="%s" type="%s" fetchpriority="high">',
            $this->e($srcset),
            $this->e($sizes),
            $this->e($mimeType),
        );

        if ($this->pusher) {
            ($this->pusher)('head', $link);
            return;
        }

        StackReplacementManager::pushStack('head', $link);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

- [ ] **Step 4.4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/PreloaderTest.php`
Expected: all pass.

- [ ] **Step 4.5: Commit**

```bash
git add src/View/Preloader.php tests/Unit/PreloaderTest.php
git commit -m "feat(responsive-images): add Preloader for head-stack link push"
```

---

## Task 5: PictureRenderer — focal-point CSS variable + aria-hidden

**Files:**
- Modify: `src/View/PictureRenderer.php`
- Modify: `tests/Unit/PictureRendererTest.php`

- [ ] **Step 5.1: Write failing tests for the two new behaviors**

Append to `tests/Unit/PictureRendererTest.php` (before the final `}`):

```php
    public function test_focal_point_emits_css_variable_and_object_position(): void
    {
        $data = $this->baseData();
        $data['img']['object_position'] = '25% 75%';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString('--focal-point:25% 75%', $html);
        $this->assertStringContainsString('object-position:25% 75%', $html);
    }

    public function test_aria_hidden_when_alt_empty(): void
    {
        $data = $this->baseData();
        $data['img']['alt'] = '';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString('alt=""', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_no_aria_hidden_when_alt_present(): void
    {
        $data = $this->baseData();
        $data['img']['alt'] = 'described';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringNotContainsString('aria-hidden', $html);
    }
```

- [ ] **Step 5.2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Unit/PictureRendererTest.php`
Expected: 3 new tests fail.

- [ ] **Step 5.3: Update PictureRenderer to emit CSS variable + aria-hidden**

In `src/View/PictureRenderer.php`, replace the `renderImg()` method with:

```php
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

        if ($attrs['alt'] === '') {
            $attrs['aria-hidden'] = 'true';
        }

        $styles = [];

        if (!empty($img['placeholder'])) {
            $safeUri = preg_replace('/[^A-Za-z0-9+\/=:;,.\-]/', '', (string) $img['placeholder']);
            $styles[] = 'background-size:cover';
            $styles[] = "background-image:url('".$safeUri."')";
        }

        if (!empty($img['object_position'])) {
            $safePos = preg_replace('/[^0-9%. \-]/', '', (string) $img['object_position']);
            if ($safePos !== '') {
                $styles[] = '--focal-point:'.$safePos;
                $styles[] = 'object-position:'.$safePos;
            }
        }

        if ($styles !== []) {
            $attrs['style'] = implode(';', $styles);
        }

        $rendered = '';
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === '') {
                if ($k !== 'alt') {
                    continue;
                }
            }
            $rendered .= ' '.$k.'="'.($k === 'style' ? $v : $this->e((string) $v)).'"';
        }

        return '<img'.$rendered.'>';
    }
```

- [ ] **Step 5.4: Update the existing object-position-combined-with-placeholder test**

The old assertion `"background-size:cover;background-image:url('data:image/jpeg;base64,AAA');object-position:50% 10%"` no longer matches — we now also emit `--focal-point:`. Replace the method `test_object_position_combined_with_placeholder` with:

```php
    public function test_object_position_combined_with_placeholder(): void
    {
        $data = $this->baseData();
        $data['img']['placeholder'] = 'data:image/jpeg;base64,AAA';
        $data['img']['object_position'] = '50% 10%';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString(
            "background-size:cover;background-image:url('data:image/jpeg;base64,AAA');--focal-point:50% 10%;object-position:50% 10%",
            $html
        );
    }
```

- [ ] **Step 5.5: Run tests to verify all pass**

Run: `./vendor/bin/phpunit tests/Unit/PictureRendererTest.php`
Expected: all tests pass, including the 3 new ones and the updated combined test.

- [ ] **Step 5.6: Commit**

```bash
git add src/View/PictureRenderer.php tests/Unit/PictureRendererTest.php
git commit -m "feat(responsive-images): add --focal-point CSS variable and aria-hidden on empty alt"
```

---

## Task 6: ResponsiveImage — route SVG / GIF / failed meta through PassthroughRenderer

**Files:**
- Modify: `src/Tags/ResponsiveImage.php`
- Modify: `tests/Feature/ResponsiveImageTagTest.php`

This task consolidates two bare-img paths: existing `$meta->failed` recovery (currently inline `renderBareImg`) and new SVG/GIF detection. Both route through `PassthroughRenderer`.

- [ ] **Step 6.1: Write failing feature tests for SVG and GIF passthrough**

Append to `tests/Feature/ResponsiveImageTagTest.php` (before the final `}`):

```php
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
```

- [ ] **Step 6.2: Update the existing failed-metadata test to allow the new PassthroughRenderer output**

The existing `test_failed_metadata_renders_bare_img` asserts specific attributes. PassthroughRenderer emits the same attributes in a different order. Verify by keeping the assertions as `assertStringContainsString` (no strict ordering). Spot-check the current test — it uses `assertStringContainsString` for each attribute, so no change needed. Confirm by reading the current test lines 191–214.

- [ ] **Step 6.3: Update the ResponsiveImage tag to route through PassthroughRenderer**

In `src/Tags/ResponsiveImage.php`:

**6.3a — Add PassthroughRenderer dependency.** Update imports to add:

```php
use Massif\ResponsiveImages\View\PassthroughRenderer;
```

Add `private ?PassthroughRenderer $passthrough;` to properties (adjacent to `$renderer`).

Update the constructor signature and body:

```php
    public function __construct(
        ?ImageResolver $resolver = null,
        ?Metadata $metadata = null,
        ?SrcsetBuilder $srcsetBuilder = null,
        ?UrlBuilder $urlBuilder = null,
        ?Placeholder $placeholder = null,
        ?PictureRenderer $renderer = null,
        ?PassthroughRenderer $passthrough = null,
        ?array $config = null,
    ) {
        $this->resolver      = $resolver;
        $this->metadata      = $metadata;
        $this->srcsetBuilder = $srcsetBuilder;
        $this->urlBuilder    = $urlBuilder;
        $this->placeholder   = $placeholder;
        $this->renderer      = $renderer;
        $this->passthrough   = $passthrough;
        $this->config        = $config;
    }
```

Update `bootDependencies()` to include:

```php
        $this->passthrough   ??= app(PassthroughRenderer::class);
```

**6.3b — Replace `renderBareImg()` calls.** Delete the entire `renderBareImg()` method. In `renderForImage()`, replace the failed-meta branch at the top with:

```php
    private function renderForImage(ResolvedImage $image, array $params): string
    {
        $meta = $this->metadata->for($image);

        if ($meta->failed) {
            return $this->passthrough->render($image, $this->passthroughParams($params, $meta));
        }

        if ($meta->mime === 'image/svg+xml' || $meta->mime === 'image/gif') {
            return $this->passthrough->render($image, $this->passthroughParams($params, $meta));
        }

        $sourceWidth = (int) ($meta->width ?: 1920);
        // ...rest unchanged
```

**6.3c — Add `passthroughParams()` helper** (place it near other private helpers at the bottom of the class):

```php
    private function passthroughParams(array $params, ImageMetadata $meta): array
    {
        return [
            'alt'      => $this->resolveAlt($params, /* image: */ $this->lastResolvedImage ?? null),
            'width'    => isset($params['width']) ? (int) $params['width'] : (int) $meta->width,
            'height'   => isset($params['height']) ? (int) $params['height'] : (int) $meta->height,
            'class'    => $params['class'] ?? null,
            'loading'  => $params['loading'] ?? 'lazy',
            'decoding' => $params['decoding'] ?? 'async',
        ];
    }
```

**Simpler — avoid the `lastResolvedImage` memo.** Instead, pass `$image` through:

Replace the helper with this signature that takes `$image` explicitly:

```php
    private function passthroughParams(array $params, ResolvedImage $image, ImageMetadata $meta): array
    {
        return [
            'alt'      => $this->resolveAlt($params, $image),
            'width'    => isset($params['width']) ? (int) $params['width'] : (int) $meta->width,
            'height'   => isset($params['height']) ? (int) $params['height'] : (int) $meta->height,
            'class'    => $params['class'] ?? null,
            'loading'  => $params['loading'] ?? 'lazy',
            'decoding' => $params['decoding'] ?? 'async',
        ];
    }
```

And in `renderForImage()`, update the calls:

```php
        if ($meta->failed) {
            return $this->passthrough->render($image, $this->passthroughParams($params, $image, $meta));
        }

        if ($meta->mime === 'image/svg+xml' || $meta->mime === 'image/gif') {
            return $this->passthrough->render($image, $this->passthroughParams($params, $image, $meta));
        }
```

Delete the old `renderBareImg()` method entirely.

- [ ] **Step 6.4: Register PassthroughRenderer singleton in the service provider**

In `src/ServiceProvider.php`, inside `register()`, after the existing `PictureRenderer` singleton:

```php
        $this->app->singleton(PassthroughRenderer::class);
```

And add the import at the top:

```php
use Massif\ResponsiveImages\View\PassthroughRenderer;
```

- [ ] **Step 6.5: Run all tests to verify**

Run: `./vendor/bin/phpunit`
Expected: all pass, including the 3 new tests and the existing `test_failed_metadata_renders_bare_img`.

- [ ] **Step 6.6: Commit**

```bash
git add src/Tags/ResponsiveImage.php src/ServiceProvider.php tests/Feature/ResponsiveImageTagTest.php
git commit -m "feat(responsive-images): route SVG/GIF/failed-meta through PassthroughRenderer"
```

---

## Task 7: ResponsiveImage — `quality` and `formats` params

**Files:**
- Modify: `src/Tags/ResponsiveImage.php`
- Modify: `tests/Feature/ResponsiveImageTagTest.php`

- [ ] **Step 7.1: Write failing feature tests**

Append to `tests/Feature/ResponsiveImageTagTest.php`:

```php
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
```

- [ ] **Step 7.2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Feature/ResponsiveImageTagTest.php`
Expected: the 4 new tests fail.

- [ ] **Step 7.3: Implement `quality` and `formats` handling**

In `src/Tags/ResponsiveImage.php`:

**7.3a — Add a helper to resolve active formats and quality.** Add these private methods near other helpers:

```php
    /**
     * @return array{0: list<string>, 1: ?int}
     */
    private function resolveFormatsAndQuality(array $params): array
    {
        $quality = isset($params['quality']) && is_numeric($params['quality'])
            ? (int) $params['quality']
            : null;

        $override = $this->parseFormats($params['formats'] ?? null);
        $formats  = $override ?? $this->enabledFormats();

        return [$formats, $quality];
    }

    /**
     * @return list<string>|null
     */
    private function parseFormats(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $list = is_array($raw)
            ? $raw
            : array_filter(array_map('trim', explode(',', (string) $raw)));

        $valid = array_values(array_filter(
            array_map('strtolower', array_map('strval', $list)),
            fn (string $f) => in_array($f, ['avif', 'webp', 'fallback'], true)
        ));

        return $valid === [] ? null : $valid;
    }

    /**
     * @return list<string>
     */
    private function enabledFormats(): array
    {
        $out = [];
        foreach (['avif', 'webp'] as $f) {
            if (! empty($this->config['formats'][$f]['enabled'])) {
                $out[] = $f;
            }
        }
        $out[] = 'fallback';
        return $out;
    }

    private function qualityFor(string $format, ?int $override): int
    {
        if ($override !== null) {
            return $override;
        }
        return (int) ($this->config['formats'][$format]['quality'] ?? 82);
    }
```

**7.3b — Route all format emission through the new helpers.** In `renderForImage()`, after parsing widths/sizes/height, compute formats once:

```php
        [$activeFormats, $qualityOverride] = $this->resolveFormatsAndQuality($params);
```

**7.3c — Update `buildFormatSources()` to use the active list:**

```php
    private function buildFormatSources(ResolvedImage $image, array $widths, string $sizes, ?int $height, ?string $fit, array $activeFormats, ?int $qualityOverride): array
    {
        $sources = [];
        foreach (['avif', 'webp'] as $format) {
            if (! in_array($format, $activeFormats, true)) {
                continue;
            }
            $sources[] = [
                'type'   => 'image/'.$format,
                'srcset' => $this->buildSrcset($image, $widths, $format, $height, $fit, $qualityOverride),
                'sizes'  => $sizes,
                'media'  => null,
            ];
        }
        return $sources;
    }
```

**7.3d — Update `buildArtDirectionSources()` similarly:**

```php
    private function buildArtDirectionSources(array $entries, string $defaultSizes, ?float $parentRatio, ?string $fit, array $activeFormats, ?int $qualityOverride): array
    {
        $result = [];
        foreach ($entries as $entry) {
            $resolved = $this->resolver->resolve($entry['src'] ?? null);
            if ($resolved === null) {
                continue;
            }

            $meta = $this->metadata->for($resolved);
            if ($meta->failed) {
                continue;
            }
            $entryRatio = $this->parseRatio($entry['ratio'] ?? null) ?? $parentRatio;
            $widths = $this->srcsetBuilder->build((int) ($meta->width ?: 1920), $this->config);
            $entryFit = $fit ?? ($entryRatio ? 'crop_focal' : 'contain');
            $height = $entryRatio
                ? (int) round((end($widths) ?: 0) / $entryRatio)
                : null;
            $sizes = (string) ($entry['sizes'] ?? $defaultSizes);
            $media = $entry['media'] ?? null;

            foreach ($activeFormats as $format) {
                $mime = $format === 'fallback'
                    ? ($meta->mime ?: 'image/jpeg')
                    : 'image/'.$format;

                $result[] = [
                    'type'   => $mime,
                    'srcset' => $this->buildSrcset($resolved, $widths, $format, $height, $entryFit, $qualityOverride),
                    'sizes'  => $sizes,
                    'media'  => $media,
                ];
            }
        }
        return $result;
    }
```

**7.3e — Update `buildSrcset()` to use qualityFor:**

```php
    private function buildSrcset(ResolvedImage $image, array $widths, string $format, ?int $height, ?string $fit, ?int $qualityOverride): string
    {
        if ($widths === []) {
            return '';
        }

        $quality = $this->qualityFor($format, $qualityOverride);
        $maxWidth = max($widths);
        $parts = [];
        foreach ($widths as $w) {
            $h = $height !== null && $maxWidth > 0
                ? (int) round($w * ($height / $maxWidth))
                : null;
            $parts[] = $this->urlBuilder->build($image,
                width: $w, format: $format, quality: $quality, height: $h, fit: $fit,
            ).' '.$w.'w';
        }
        return implode(', ', $parts);
    }
```

**7.3f — Update the fallback `<img>` URL build in `renderForImage()`:**

```php
        $fallbackQuality = $this->qualityFor('fallback', $qualityOverride);
        $imgSrc = $this->urlBuilder->build(
            $fallbackImage,
            width: $fallbackWidth,
            format: 'fallback',
            quality: $fallbackQuality,
            height: $ratio ? (int) round($fallbackWidth / $ratio) : null,
            fit: $fit,
        );

        $fallbackSrcset = $this->buildSrcset($fallbackImage, $widths, 'fallback', $srcsetHeight, $fit, $qualityOverride);
```

**7.3g — Update the calls in `renderForImage()` that now need the extra args:**

```php
        if (is_array($artDirection) && $artDirection !== []) {
            $sources = $this->buildArtDirectionSources($artDirection, $sizes, $ratio, $fit, $activeFormats, $qualityOverride);
            // ...
        } else {
            $sources = $this->buildFormatSources($image, $widths, $sizes, $srcsetHeight, $fit, $activeFormats, $qualityOverride);
            // ...
        }
```

- [ ] **Step 7.4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit`
Expected: all pass (new quality/formats tests + all existing tests).

- [ ] **Step 7.5: Commit**

```bash
git add src/Tags/ResponsiveImage.php tests/Feature/ResponsiveImageTagTest.php
git commit -m "feat(responsive-images): add per-tag quality and formats params"
```

---

## Task 8: ResponsiveImage — Glide passthrough filter params

**Files:**
- Modify: `src/Tags/ResponsiveImage.php`
- Modify: `tests/Feature/ResponsiveImageTagTest.php`

- [ ] **Step 8.1: Write failing feature tests**

Append to `tests/Feature/ResponsiveImageTagTest.php`:

```php
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
```

- [ ] **Step 8.2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Feature/ResponsiveImageTagTest.php`
Expected: the 3 new tests fail.

- [ ] **Step 8.3: Wire the passthrough extras through to UrlBuilder**

In `src/Tags/ResponsiveImage.php`:

**8.3a — Add the whitelist constant and extras extractor.** Near the top of the class:

```php
    private const PASSTHROUGH_KEYS = [
        'bg', 'blur', 'brightness', 'contrast', 'filter',
        'flip', 'gamma', 'orient', 'pixelate', 'sharpen',
    ];
```

Helper method (with other helpers):

```php
    /**
     * @return array<string, mixed>
     */
    private function extrasFromParams(array $params): array
    {
        $out = [];
        foreach (self::PASSTHROUGH_KEYS as $k) {
            if (array_key_exists($k, $params) && $params[$k] !== '' && $params[$k] !== null) {
                $out[$k] = $params[$k];
            }
        }
        return $out;
    }
```

**8.3b — Thread extras into every UrlBuilder call.**

Change `buildSrcset()` signature to accept `array $extras`:

```php
    private function buildSrcset(ResolvedImage $image, array $widths, string $format, ?int $height, ?string $fit, ?int $qualityOverride, array $extras = []): string
    {
        if ($widths === []) {
            return '';
        }

        $quality = $this->qualityFor($format, $qualityOverride);
        $maxWidth = max($widths);
        $parts = [];
        foreach ($widths as $w) {
            $h = $height !== null && $maxWidth > 0
                ? (int) round($w * ($height / $maxWidth))
                : null;
            $parts[] = $this->urlBuilder->build($image,
                width: $w, format: $format, quality: $quality, height: $h, fit: $fit,
                extras: $extras,
            ).' '.$w.'w';
        }
        return implode(', ', $parts);
    }
```

Change `buildFormatSources()` signature and body:

```php
    private function buildFormatSources(ResolvedImage $image, array $widths, string $sizes, ?int $height, ?string $fit, array $activeFormats, ?int $qualityOverride, array $extras): array
    {
        $sources = [];
        foreach (['avif', 'webp'] as $format) {
            if (! in_array($format, $activeFormats, true)) {
                continue;
            }
            $sources[] = [
                'type'   => 'image/'.$format,
                'srcset' => $this->buildSrcset($image, $widths, $format, $height, $fit, $qualityOverride, $extras),
                'sizes'  => $sizes,
                'media'  => null,
            ];
        }
        return $sources;
    }
```

Change `buildArtDirectionSources()` to accept `$extras`, passing it to every `buildSrcset()` call inside.

**8.3c — In `renderForImage()`, extract and thread extras:**

```php
        $extras = $this->extrasFromParams($params);

        // When calling buildFormatSources:
        $sources = $this->buildFormatSources($image, $widths, $sizes, $srcsetHeight, $fit, $activeFormats, $qualityOverride, $extras);

        // When calling buildArtDirectionSources:
        $sources = $this->buildArtDirectionSources($artDirection, $sizes, $ratio, $fit, $activeFormats, $qualityOverride, $extras);

        // When building the fallback <img> src:
        $imgSrc = $this->urlBuilder->build(
            $fallbackImage,
            width: $fallbackWidth,
            format: 'fallback',
            quality: $fallbackQuality,
            height: $ratio ? (int) round($fallbackWidth / $ratio) : null,
            fit: $fit,
            extras: $extras,
        );

        // When building fallbackSrcset:
        $fallbackSrcset = $this->buildSrcset($fallbackImage, $widths, 'fallback', $srcsetHeight, $fit, $qualityOverride, $extras);
```

- [ ] **Step 8.4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit`
Expected: all pass.

- [ ] **Step 8.5: Commit**

```bash
git add src/Tags/ResponsiveImage.php tests/Feature/ResponsiveImageTagTest.php
git commit -m "feat(responsive-images): add Glide passthrough filter params to tag"
```

---

## Task 9: ResponsiveImage — wildcard form `{{ tag:field }}`

**Files:**
- Modify: `src/Tags/ResponsiveImage.php`
- Modify: `tests/Feature/ResponsiveImageTagTest.php`

- [ ] **Step 9.1: Write failing feature test**

Append to `tests/Feature/ResponsiveImageTagTest.php`:

```php
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
```

- [ ] **Step 9.2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Feature/ResponsiveImageTagTest.php`
Expected: both new tests fail (method doesn't exist; `setContext`/`setParameters` are Statamic Tags framework methods already inherited).

- [ ] **Step 9.3: Implement wildcard method**

In `src/Tags/ResponsiveImage.php`, add after `index()`:

```php
    public function wildcard(string $tag): string
    {
        $this->bootDependencies();

        $value = $this->context->value($tag);
        if ($value === null || $value === '') {
            return '';
        }

        $params = $this->params->all();
        $params['src'] = $value;

        return $this->renderFromParams($params);
    }
```

- [ ] **Step 9.4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Feature/ResponsiveImageTagTest.php`
Expected: all pass.

- [ ] **Step 9.5: Commit**

```bash
git add src/Tags/ResponsiveImage.php tests/Feature/ResponsiveImageTagTest.php
git commit -m "feat(responsive-images): add wildcard form {{ tag:field }}"
```

---

## Task 10: Tags\Pic — short configurable alias

**Files:**
- Create: `src/Tags/Pic.php`
- Modify: `src/ServiceProvider.php`
- Modify: `tests/Feature/ResponsiveImageTagTest.php`

- [ ] **Step 10.1: Write failing test for the alias class**

Append to `tests/Feature/ResponsiveImageTagTest.php`:

```php
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

        $args = [$resolver, $metadata, $srcset, $urls, $placeholder, new PictureRenderer(), new \Massif\ResponsiveImages\View\PassthroughRenderer()];

        $tag = new ResponsiveImage(...$args, config: $baseConfig);
        $pic = new \Massif\ResponsiveImages\Tags\Pic(...$args, config: $baseConfig);

        $params = ['src' => '/p.jpg', 'alt' => 'x'];

        $this->assertSame($tag->renderFromParams($params), $pic->renderFromParams($params));
    }

    public function test_pic_default_handle_is_pic(): void
    {
        $this->assertSame('pic', \Massif\ResponsiveImages\Tags\Pic::$handle);
    }
```

- [ ] **Step 10.2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Feature/ResponsiveImageTagTest.php`
Expected: both new tests fail (class doesn't exist).

- [ ] **Step 10.3: Create the Pic subclass**

Create `src/Tags/Pic.php`:

```php
<?php

namespace Massif\ResponsiveImages\Tags;

class Pic extends ResponsiveImage
{
    protected static $handle = 'pic';
}
```

- [ ] **Step 10.4: Wire Pic registration in the service provider**

Edit `src/ServiceProvider.php`:

**10.4a — Import Pic:**

```php
use Massif\ResponsiveImages\Tags\Pic;
```

**10.4b — Remove the fixed `$tags` array and build it dynamically.** Replace:

```php
    protected $tags = [
        ResponsiveImage::class,
    ];
```

with:

```php
    protected $tags = [];

    public function __construct($app)
    {
        parent::__construct($app);

        $this->tags = [ResponsiveImage::class];

        $alias = (string) ($app['config']->get('responsive-images.tag_alias') ?? '');
        $alias = trim($alias);

        if ($alias !== '' && $alias !== 'responsive_image') {
            Pic::$handle = $alias;
            $this->tags[] = Pic::class;
        }
    }
```

Note: `AddonServiceProvider`'s constructor signature is `__construct($app)` and it requires calling the parent. The `$app['config']` lookup happens before `register()` because Statamic reads `$this->tags` during `register()`.

- [ ] **Step 10.5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit`
Expected: all pass.

- [ ] **Step 10.6: Commit**

```bash
git add src/Tags/Pic.php src/ServiceProvider.php tests/Feature/ResponsiveImageTagTest.php
git commit -m "feat(responsive-images): add Pic short-alias tag, configurable via tag_alias"
```

---

## Task 11: Config — `tag_alias`, `preload`, flip `default_sizes`

**Files:**
- Modify: `config/responsive-images.php`
- Modify: `tests/Feature/ResponsiveImageTagTest.php`

- [ ] **Step 11.1: Write failing test for the new config defaults**

Append to `tests/Feature/ResponsiveImageTagTest.php`:

```php
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
```

- [ ] **Step 11.2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Feature/ResponsiveImageTagTest.php`
Expected: the 3 new tests fail.

- [ ] **Step 11.3: Update the config file**

Replace the content of `config/responsive-images.php` with:

```php
<?php

return [
    // Srcset generation (next/image model).
    // The srcset pool is device_sizes ∪ image_sizes, filtered to
    // widths <= the source image's intrinsic width.
    'device_sizes' => [640, 750, 828, 1080, 1200, 1920, 2048, 3840],
    'image_sizes'  => [16, 32, 48, 64, 96, 128, 256, 384],

    // Default sizes attribute when the tag caller does not provide one.
    // Layout-aware default: caps hero images around 640px at desktop,
    // scales to 50vw at tablet, 90vw at mobile. Override per-tag with
    // sizes="..." when your layout differs.
    'default_sizes' => '(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw',

    // Short alias handle for the tag. `null` disables the alias.
    // When non-null, the addon registers a second tag class (`Pic`)
    // with this handle, sharing all behavior with {{ responsive_image }}.
    'tag_alias' => 'pic',

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

    // Preload behavior when tag is called with preload="true".
    'preload' => [
        // When true, also sets loading="eager" on the <img> (unless
        // the caller passed an explicit `loading`).
        'auto_eager' => true,
        // When true, also sets fetchpriority="high" on the <img>
        // (unless the caller passed an explicit `fetchpriority`).
        'auto_priority' => true,
    ],

    'glide' => [
        // Glide fit mode used when the tag is given a `ratio` (or explicit
        // `width` + `height`) and no per-tag `fit` override. Valid Glide fits:
        //   'crop_focal'   — crop to exact dimensions, honoring the asset's
        //                    focal point when set (default)
        //   'crop'         — crop to exact dimensions, centered
        //   'crop-{x}-{y}' — crop at explicit focal coordinates, e.g. 'crop-50-50'
        //   'contain'      — fit inside the box, preserving aspect, may letterbox
        //   'max'          — like contain but never upscales
        //   'fill'         — fill the box, may crop
        //   'stretch'      — distort to exact dimensions
        //
        // When NO ratio is set, this option is ignored: the tag passes only
        // `w` (plus `fit=contain`) so Glide scales proportionally from the
        // source. That's how we keep intrinsic dimensions CLS-safe.
        'default_fit' => 'crop_focal',
    ],

    'cache' => [
        'store'        => null,
        'prefix'       => 'respimg',
        // TTL (seconds) for successful metadata reads. Default: 90 days.
        // The cache key includes the asset mtime, so updated files are picked
        // up immediately; this TTL only bounds how long stale (unused) keys
        // linger in the store.
        'metadata_ttl' => 7_776_000,
        // TTL (seconds) for failed metadata reads (corrupt/missing files, I/O
        // errors). Short enough to recover quickly once the underlying issue
        // is fixed, long enough to prevent per-request re-reads on a
        // high-traffic page.
        'sentinel_ttl' => 60,
    ],
];
```

- [ ] **Step 11.4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit`
Expected: all pass.

- [ ] **Step 11.5: Commit**

```bash
git add config/responsive-images.php tests/Feature/ResponsiveImageTagTest.php
git commit -m "feat(responsive-images): layout-aware default sizes, tag_alias, preload config"
```

---

## Task 12: ResponsiveImage — `preload` param + auto-eager / auto-priority

**Files:**
- Modify: `src/Tags/ResponsiveImage.php`
- Modify: `src/ServiceProvider.php`
- Modify: `tests/Feature/ResponsiveImageTagTest.php`

- [ ] **Step 12.1: Write failing feature tests**

The tests need to observe what `Preloader` pushed. We'll inject a capturing `Preloader` into the tag constructor.

Update the `makeTag` helper in `tests/Feature/ResponsiveImageTagTest.php` to accept and return a capturing preloader. First, change the `makeTag` signature:

```php
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
```

Append the new tests:

```php
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
```

- [ ] **Step 12.2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Feature/ResponsiveImageTagTest.php`
Expected: the 6 new tests fail (Preloader param missing from constructor; no preload handling).

- [ ] **Step 12.3: Add Preloader dependency and preload handling to the tag**

In `src/Tags/ResponsiveImage.php`:

**12.3a — Import and add property:**

```php
use Massif\ResponsiveImages\View\Preloader;
```

Add `private ?Preloader $preloader;` next to the other `private ?X $y;` fields.

**12.3b — Update the constructor signature to accept Preloader:**

```php
    public function __construct(
        ?ImageResolver $resolver = null,
        ?Metadata $metadata = null,
        ?SrcsetBuilder $srcsetBuilder = null,
        ?UrlBuilder $urlBuilder = null,
        ?Placeholder $placeholder = null,
        ?PictureRenderer $renderer = null,
        ?PassthroughRenderer $passthrough = null,
        ?Preloader $preloader = null,
        ?array $config = null,
    ) {
        $this->resolver      = $resolver;
        $this->metadata      = $metadata;
        $this->srcsetBuilder = $srcsetBuilder;
        $this->urlBuilder    = $urlBuilder;
        $this->placeholder   = $placeholder;
        $this->renderer      = $renderer;
        $this->passthrough   = $passthrough;
        $this->preloader     = $preloader;
        $this->config        = $config;
    }
```

Update `bootDependencies()`:

```php
        $this->preloader     ??= app(Preloader::class);
```

**12.3c — Handle `preload` param in `renderForImage()`.**

Inside `renderForImage()`, after `$sources` has been built (both for format sources and art-direction sources) but before computing `$data`, add:

```php
        $preload = $this->bool($params['preload'] ?? false);

        // Preload the highest-priority format's srcset. For art-direction,
        // preload the primary <img>'s fallback srcset (per-breakpoint preload
        // is out of scope).
        if ($preload) {
            $preloadSource = null;
            foreach ($sources as $s) {
                if (empty($s['media'])) {
                    $preloadSource = $s;
                    break;
                }
            }
            if ($preloadSource !== null) {
                $this->preloader->push(
                    srcset: (string) $preloadSource['srcset'],
                    sizes: (string) $preloadSource['sizes'],
                    mimeType: (string) $preloadSource['type'],
                );
            } elseif ($fallbackSrcset !== '') {
                $mime = $meta->mime !== '' ? $meta->mime : 'image/jpeg';
                $this->preloader->push(
                    srcset: $fallbackSrcset,
                    sizes: $sizes,
                    mimeType: $mime,
                );
            }
        }
```

Note: the `foreach` above is a safety net for the art-direction case where every source has a `media` attribute — we fall back to the `<img>`'s own srcset. For the simple format-sources case, the first source (AVIF if enabled, else WebP) has `media => null` and matches the `empty($s['media'])` check.

**12.3d — Auto-eager / auto-priority logic.**

Replace the `loading` / `fetchpriority` defaults in the `$data['img']` construction. Before the `$data = [...]` block:

```php
        $loadingDefault = 'lazy';
        $fetchPriorityDefault = 'auto';

        if ($preload) {
            if (! empty($this->config['preload']['auto_eager']) && ! array_key_exists('loading', $params)) {
                $loadingDefault = 'eager';
            }
            if (! empty($this->config['preload']['auto_priority']) && ! array_key_exists('fetchpriority', $params)) {
                $fetchPriorityDefault = 'high';
            }
        }
```

Then in the `$data['img']` array, replace:

```php
                'loading'         => $params['loading'] ?? 'lazy',
                'decoding'        => $params['decoding'] ?? 'async',
                'fetchpriority'   => $params['fetchpriority'] ?? 'auto',
```

with:

```php
                'loading'         => $params['loading'] ?? $loadingDefault,
                'decoding'        => $params['decoding'] ?? 'async',
                'fetchpriority'   => $params['fetchpriority'] ?? $fetchPriorityDefault,
```

- [ ] **Step 12.4: Register Preloader as a singleton in the service provider**

In `src/ServiceProvider.php`, inside `register()`:

```php
        $this->app->singleton(Preloader::class);
```

Add the import:

```php
use Massif\ResponsiveImages\View\Preloader;
```

- [ ] **Step 12.5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit`
Expected: all pass.

- [ ] **Step 12.6: Commit**

```bash
git add src/Tags/ResponsiveImage.php src/ServiceProvider.php tests/Feature/ResponsiveImageTagTest.php
git commit -m "feat(responsive-images): add preload param with auto-eager/auto-priority"
```

---

## Task 13: Documentation — README and CHANGELOG

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 13.1: Update README parameter table and add new sections**

In `README.md`:

**13.1a — Add new rows to the parameter table** (alphabetize within the table or keep grouped; follow current ordering). Insert between existing rows:

```markdown
| `preload` | bool | Push a `<link rel="preload" as="image" …>` for the top enabled format onto the Antlers `head` stack. Requires `{{ stack name="head" }}` in the layout. See [Preload](#preload) below. |
| `quality` | int | Override the quality for all formats on this render. Defaults to per-format quality from config. |
| `formats` | csv\|array | Limit which formats are emitted. E.g. `formats="webp,fallback"`. Valid entries: `avif`, `webp`, `fallback`. |
| `blur` | int | Glide blur passthrough. |
| `brightness` | int | Glide brightness passthrough (-100..100). |
| `contrast` | int | Glide contrast passthrough (-100..100). |
| `sharpen` | int | Glide sharpen passthrough (0..100). |
| `gamma` | float | Glide gamma passthrough. |
| `pixelate` | int | Glide pixelate passthrough. |
| `filter` | string | Glide filter passthrough (e.g. `sepia`, `greyscale`). |
| `flip` | string | Glide flip passthrough (`h`, `v`, `both`). |
| `orient` | int\|string | Glide orient passthrough (exif value or `0`/`90`/`180`/`270`). |
| `bg` | string | Glide background colour passthrough (hex, rgb, rgba). |
```

**13.1b — Add a "Tag alias" section after "Parameters":**

```markdown
## Tag alias

For brevity, the addon ships a short alias `{{ pic }}` alongside the canonical `{{ responsive_image }}`. Both tags share every behavior, parameter, and wildcard form:

```antlers
{{ pic :src="hero" alt="A sunset" }}
{{ pic:hero alt="A sunset" }}
```

The alias handle is configurable:

```php
// config/responsive-images.php
'tag_alias' => 'pic',   // set to any handle, or null to disable
```
```

**13.1c — Add a "Wildcard form" section:**

```markdown
## Wildcard form

Resolve `src` from the template context by field name:

```antlers
{{ responsive_image:hero }}
{{ pic:hero alt="Custom alt" }}
```

The tag suffix (after the `:`) is read from `$this->context`, so any field, augmented asset, or template variable on the current scope is usable.
```

**13.1d — Add a "Preload" section:**

```markdown
## Preload

For above-the-fold images (LCP candidates), set `preload="true"`:

```antlers
{{ pic :src="hero" alt="…" preload="true" }}
```

The tag pushes a `<link rel="preload" as="image" imagesrcset=… imagesizes=… type="image/avif" fetchpriority="high">` onto the Antlers `head` stack. Your layout must render that stack:

```antlers
<head>
    {{ stack name="head" }}
</head>
```

If the stack is absent, Statamic silently discards the push — no error, but also no preload link in the output.

When `preload="true"` is set, the tag also:

- Sets `loading="eager"` on the `<img>` (unless you passed `loading="..."` explicitly).
- Sets `fetchpriority="high"` on the `<img>` (unless you passed `fetchpriority="..."` explicitly).

Both auto-behaviors are togglable in config:

```php
'preload' => [
    'auto_eager'    => true,
    'auto_priority' => true,
],
```

**Format selection.** The preload link targets the highest-priority enabled format (AVIF → WebP → fallback). Browsers that can't decode the format (e.g. older browsers on an AVIF link) skip the preload — safe, because `type=` is set.

**Limitations.**
- Per-breakpoint preload for art-directed sources is not supported in v1 — the preload targets the primary `src`.
- Requires a `head` stack in your layout.
```

**13.1e — Add an "SVG and GIF" section:**

```markdown
## SVG and GIF

SVG (`image/svg+xml`) and GIF (`image/gif`) sources skip the Glide pipeline entirely. The tag emits a plain `<img>` with the original URL, `width`/`height` from metadata when available, `class`, `loading`, `decoding`, and `aria-hidden="true"` when `alt` is empty. No `<picture>`, no `srcset`, no re-encoding — raster transforms would either produce meaningless output (SVG) or lose animation (GIF).

Glide passthrough params (`blur`, `sharpen`, etc.) are ignored for these sources.
```

**13.1f — Update the "Classes" section's example for the new default `sizes`.** No code change required there; just double-check the example still makes sense with the new default.

**13.1g — Update the config block in README** to reflect the new keys. Replace the config block (around line 127–156) with:

```php
return [
    'device_sizes'   => [640, 750, 828, 1080, 1200, 1920, 2048, 3840],
    'image_sizes'    => [16, 32, 48, 64, 96, 128, 256, 384],
    'default_sizes'  => '(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw',
    'tag_alias'      => 'pic',
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

    'preload' => [
        'auto_eager'    => true,
        'auto_priority' => true,
    ],

    'glide' => [
        'default_fit' => 'crop_focal',
    ],

    'cache' => [
        'store'        => null,
        'prefix'       => 'respimg',
        'metadata_ttl' => 7_776_000,
        'sentinel_ttl' => 60,
    ],
];
```

- [ ] **Step 13.2: Update CHANGELOG**

In `CHANGELOG.md`, update the `## Unreleased` section by adding a new "Breaking" sub-section at the top and new "Added" entries. The full `## Unreleased` section should read:

```markdown
## Unreleased

### Breaking
- **Default `sizes` flipped** from `'100vw'` to a layout-aware default: `'(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw'`. Images served on desktop are now ~640px wide by default, a meaningful bandwidth win on typical content-constrained layouts. Users who relied on the old behavior should publish the config and set `'default_sizes' => '100vw'`.

### Added
- **`{{ pic }}` short alias** alongside `{{ responsive_image }}`, with a configurable handle via `config('responsive-images.tag_alias')` (default `'pic'`, `null` disables).
- **Wildcard form** `{{ responsive_image:field }}` / `{{ pic:field }}` — resolves `src` from the template context.
- **SVG / GIF passthrough** — `image/svg+xml` and `image/gif` sources bypass Glide and render a plain `<img>` with intrinsic dimensions, `class`, `loading`, `decoding`, and `aria-hidden` when alt is empty.
- **Array `src` unwrap** — fields augmented to `[$asset]` (common Statamic behavior) now resolve correctly.
- **`preload="true"` param** — pushes a `<link rel="preload" as="image">` onto the Antlers `head` stack for above-the-fold images. Requires `{{ stack name="head" }}` in the layout. Auto-sets `loading="eager"` and `fetchpriority="high"` on the `<img>` by default (togglable via `config.preload.auto_eager` / `auto_priority`).
- **`quality` and `formats` tag params** — per-render quality override; per-render restriction of emitted formats (e.g. `formats="webp,fallback"`).
- **Glide passthrough filter params** — `bg`, `blur`, `brightness`, `contrast`, `filter`, `flip`, `gamma`, `orient`, `pixelate`, `sharpen`. Whitelisted in `UrlBuilder` to prevent arbitrary method invocation.
- **`--focal-point` CSS variable** emitted alongside `object-position: …` on images with a focal point. Tailwind users can reference `var(--focal-point)`; non-Tailwind users still get the direct inline style.
- **`aria-hidden="true"`** on `<img>` elements with an empty `alt`, including SVG / GIF passthrough.

### Config
- **New:** `'tag_alias' => 'pic'` — short alias tag handle.
- **New:** `'preload' => ['auto_eager' => true, 'auto_priority' => true]` — ergonomic auto-sets when `preload="true"` is used.
- **Changed (breaking):** `'default_sizes'` default — see Breaking above.
```

Keep the existing Unreleased sub-sections from the previous edit (the "Changed / Added / Config / Upgrade notes" from the color-profile work) below this block.

- [ ] **Step 13.3: Run the full test suite as a smoke check**

Run: `./vendor/bin/phpunit`
Expected: all pass (docs changes don't affect tests).

- [ ] **Step 13.4: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m "docs(responsive-images): document pic alias, wildcard, preload, SVG/GIF, passthrough params"
```

---

## Task 14: Final verification

- [ ] **Step 14.1: Full test suite**

Run: `./vendor/bin/phpunit`
Expected: zero failures, zero errors. Every task's tests green + all pre-existing tests green.

- [ ] **Step 14.2: Sanity-check git log**

Run: `git log --oneline main..HEAD`
Expected: 13 new commits on `improve-with-peak`, each with a clear `feat(responsive-images)` / `docs(responsive-images)` prefix.

- [ ] **Step 14.3: Confirm file inventory matches the plan**

Run:
```bash
ls src/Tags/ src/View/ src/Image/
ls tests/Unit/ tests/Feature/
```

Expected new files present:
- `src/Tags/Pic.php`
- `src/View/Preloader.php`
- `src/View/PassthroughRenderer.php`
- `tests/Unit/PreloaderTest.php`
- `tests/Unit/PassthroughRendererTest.php`
- `tests/Unit/UrlBuilderExtrasTest.php`

- [ ] **Step 14.4: Grep for placeholder leftovers**

Run: `grep -rn "TODO\|FIXME\|XXX" src/ tests/ config/ README.md CHANGELOG.md` (outside vendor)
Expected: no new hits introduced by this branch.

---

## Spec Coverage Self-Check

| Spec requirement | Task |
|---|---|
| Short configurable tag alias (`pic`) | 10, 11 |
| Wildcard form `{{ tag:field }}` | 9 |
| SVG / GIF passthrough | 3, 6 |
| Array `src` unwrap | 1 |
| `preload` with head-stack push | 4, 12 |
| Auto-eager / auto-priority when preload=true | 12 |
| `--focal-point` CSS variable | 5 |
| `aria-hidden` on empty alt (picture path) | 5 |
| `aria-hidden` on empty alt (passthrough path) | 3 |
| Glide passthrough knobs (10 filters) | 2, 8 |
| Per-tag `quality` override | 7 |
| Per-tag `formats` override | 7 |
| Default `sizes` flip (breaking) | 11 |
| Config keys: `tag_alias`, `preload` block | 11 |
| PassthroughRenderer consolidation of failed-meta | 3, 6 |
| README updates | 13 |
| CHANGELOG entry (Breaking + Added) | 13 |
| ServiceProvider: Preloader + Passthrough singletons | 6, 10, 12 |
