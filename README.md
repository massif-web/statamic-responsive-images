# Responsive Images

A production-ready Statamic 6 addon that renders responsive `<picture>` elements from a single Antlers tag. Format negotiation (AVIF → WebP → fallback) is handled by the browser, intrinsic dimensions are always set to prevent CLS, and a tiny inline LQIP is rendered behind the image while it loads.

Srcset generation follows the [next/image](https://nextjs.org/docs/app/api-reference/components/image) model: a pool of candidate widths built from `device_sizes` ∪ `image_sizes`, capped at the source image's intrinsic width.

## Requirements

- PHP 8.2+
- Statamic 6
- Statamic Glide enabled (default)

## Installation

```bash
composer require massif/responsive-images
```

The addon auto-registers via its service provider. Publish the config if you want to tweak defaults:

```bash
php artisan vendor:publish --tag=responsive-images-config
```

## Quickstart

```antlers
{{ responsive_image src="hero.jpg" alt="A sunset over the coast" }}
```

Output (simplified):

```html
<picture>
  <source type="image/avif" srcset="/img/.../avif 640w, ..." sizes="100vw">
  <source type="image/webp" srcset="/img/.../webp 640w, ..." sizes="100vw">
  <img src="/img/.../828w" srcset="..." sizes="100vw"
       width="1600" height="900" alt="A sunset over the coast"
       loading="lazy" decoding="async" fetchpriority="auto"
       style="background-size:cover;background-image:url('data:image/jpeg;base64,...')">
</picture>
```

With an asset field:

```antlers
{{ responsive_image :src="hero" ratio="16/9" sizes="(min-width: 1024px) 50vw, 100vw" }}
```

## Parameters

| Param | Type | Description |
|---|---|---|
| `src` | string\|Asset | **Required.** URL, `assets::id`, or Asset instance. Empty values render nothing. |
| `alt` | string | Alt text. Falls back to the asset's `alt` field. Missing alt is logged as a warning. |
| `sizes` | string | `sizes` attribute. Defaults to `default_sizes` from config. |
| `widths` | array\|csv | Override the srcset pool. E.g. `widths="400,800,1200"`. |
| `ratio` | string | Force an aspect ratio. Accepts `16/9` or `16:9`. Drives crop height on every srcset entry. |
| `width` | int | Explicit intrinsic width. |
| `height` | int | Explicit intrinsic height. |
| `fit` | string | Glide fit mode. Defaults to `glide.default_fit` (`crop_focal`) when a ratio is set. |
| `class` | string | Class on the wrapper element when `figure` or `ratio_wrapper` is used. |
| `img_class` | string | Class applied directly to the `<img>`. |
| `loading` | string | `lazy` (default), `eager`. |
| `decoding` | string | `async` (default), `sync`, `auto`. |
| `fetchpriority` | string | `auto` (default), `high`, `low`. |
| `placeholder` | bool | Set `false` to disable the inline LQIP for this tag. |
| `figure` | bool | Wrap output in `<figure>`. |
| `caption` | string | Caption text. Implies `figure=true`. |
| `ratio_wrapper` | bool | Wrap output in a `<div style="aspect-ratio:…">`. |
| `sources` | array | Art-direction sources. See below. |

## Art direction

Pass an array of entries. Each entry becomes its own set of `<source>` elements with the given `media` query. Entries earlier in the array win (browser picks the first match).

```antlers
{{ responsive_image
    :src="hero_desktop"
    alt="Hero"
    :sources="[
      { src: hero_mobile, media: '(max-width: 768px)' },
      { src: hero_desktop }
    ]"
}}
```

Each entry accepts `src`, `media`, `sizes`, and `ratio`. The last entry's `src` is used as the `<img>` fallback.

## Config

`config/responsive-images.php`:

```php
return [
    'device_sizes'   => [640, 750, 828, 1080, 1200, 1920, 2048, 3840],
    'image_sizes'    => [16, 32, 48, 64, 96, 128, 256, 384],
    'default_sizes'  => '100vw',
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
        'store'  => null,      // null = default cache store
        'ttl'    => null,      // null = forever
        'prefix' => 'respimg',
    ],
];
```

**`device_sizes` vs `image_sizes`.** Device sizes cover full-width images at common device breakpoints. Image sizes cover small images (thumbnails, icons). The final srcset pool is their union, deduped, sorted, and capped at the source image's intrinsic width — the browser then picks the best candidate based on `sizes`.

**Format quality.** AVIF defaults to 50, WebP to 75, fallback to 82. Lower values ship smaller bytes; tune per project.

## Performance notes

- **Metadata is cached** by `{prefix}:meta:{id}:{mtime}`. Changing the source file invalidates automatically.
- **LQIPs are cached** by `{prefix}:lqip:{id}:{mtime}` and inlined as a base64 data URI (no extra request).
- **Glide does the heavy lifting** — transformed variants are cached on disk by Statamic's Glide pipeline. Cold requests do the work once.
- **No `Accept` sniffing.** Format negotiation is entirely browser-side via `<picture>`, so the response is cacheable by any CDN.

## Caveats

- **AVIF encoding is slow.** First request per (image, width) can take a few seconds. Warm critical pages at deploy time if this matters.
- **AVIF requires Imagick with libheif** or a recent GD build. If your image driver can't emit AVIF, disable it:

  ```php
  'formats' => ['avif' => ['enabled' => false, 'quality' => 50]],
  ```

- **Non-image assets are skipped.** Unresolvable `src` values log a warning and render nothing.
- **Plain URL `src` values bypass mtime-based cache invalidation.** Only Statamic assets (`assets::id` or asset instances) carry an mtime; URL strings cache under a zero mtime, so replacing a file at the same URL will not invalidate metadata or LQIPs. Clear the cache manually (or bump the config `cache.prefix`) after such replacements.
- **Missing alt text is logged** but does not throw. Fix the warning or pass `alt=""` explicitly for decorative images.

## License

MIT
