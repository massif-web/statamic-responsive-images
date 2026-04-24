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
| `fit` | string | Glide fit mode. Defaults to `glide.default_fit` (`crop_focal`) when a ratio is set. Without a ratio, URLs use `fit=contain` so Glide scales proportionally. |
| `class` | string | Class for the outermost rendered element. Lands on the wrapper when `figure` or `ratio_wrapper` is used, otherwise on the `<img>`. |
| `img_class` | string | Class applied directly to the `<img>`. Merges with `class` when there's no wrapper. |
| `loading` | string | `lazy` (default), `eager`. |
| `decoding` | string | `async` (default), `sync`, `auto`. |
| `fetchpriority` | string | `auto` (default), `high`, `low`. |
| `preload` | bool | Push a `<link rel="preload" as="image" …>` for the top enabled format onto the Antlers `head` stack. Works out of the box — Statamic's default head partial already renders that stack. See [Preload](#preload) below. |
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
| `placeholder` | bool | Set `false` to disable the inline LQIP for this tag. |
| `figure` | bool | Wrap output in `<figure>`. |
| `caption` | string\|bool | Caption text (only rendered in figure mode). When omitted, the figure auto-captions from the resolved `alt` text. Pass `caption="false"` to disable the auto-caption. |
| `ratio_wrapper` | bool | Wrap output in a `<div style="aspect-ratio:…">`. |
| `sources` | array | Art-direction sources. See below. |

### Classes

`class` targets the outermost rendered element. When you wrap the output in a `<figure>` or a ratio `<div>`, the class lands on that wrapper; otherwise it lands on the `<img>`. If you also pass `img_class` in the wrapperless case, both are merged on the `<img>`.

```antlers
{{# No wrapper → class goes on the <img>. #}}
{{ responsive_image :src="hero" class="rounded shadow" }}

{{# Wrapper present → class goes on the <figure>, img_class on the <img>. #}}
{{ responsive_image :src="hero" figure="true" class="card" img_class="object-cover" }}
```

### Captions

In figure mode, the tag auto-captions from the resolved `alt` text (which itself falls back to the asset's `alt` field). Explicit `caption` wins. Pass `caption="false"` to render a `<figure>` without a `<figcaption>`.

```antlers
{{# Auto-caption from alt (figure=true) #}}
{{ responsive_image :src="hero" alt="Sunset over the coast" figure="true" }}
{{# → <figure>…<figcaption>Sunset over the coast</figcaption></figure> #}}

{{# Explicit caption #}}
{{ responsive_image :src="hero" figure="true" caption="Shot in Lisbon" }}

{{# Figure without caption #}}
{{ responsive_image :src="hero" figure="true" caption="false" }}
```

### Focal point → `object-position`

When the source is a Statamic asset with a focal point set in the CP, the tag emits an inline `object-position: x% y%` on the `<img>` so CSS-cropped layouts (e.g. `object-fit: cover` on a fixed-aspect container) keep the subject in frame. This is on by default, is a no-op when no focal point is set, and costs nothing when the CSS doesn't use `object-fit`.

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

## Wildcard form

Resolve `src` from the template context by field name:

```antlers
{{ responsive_image:hero }}
{{ pic:hero alt="Custom alt" }}
```

The tag suffix (after the `:`) is read from `$this->context`, so any field, augmented asset, or template variable on the current scope is usable.

## Preload

For above-the-fold images (LCP candidates), set `preload="true"`:

```antlers
{{ pic :src="hero" alt="…" preload="true" }}
```

The tag pushes a `<link rel="preload" as="image" imagesrcset=… imagesizes=… type="image/avif" fetchpriority="high">` onto the Antlers `head` stack. Statamic's default head partial (`vendor/statamic/cms/resources/views/partials/head.blade.php`) already renders that stack, so preload works out of the box on a stock layout.

If you've replaced the default head partial with a fully custom one, make sure it still renders the stack:

```antlers
<head>
    {{ stack name="head" }}
</head>
```

When the stack is absent, Statamic silently discards the push — no error, but also no preload link in the output.

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
- Needs a rendered `head` stack in your layout. Statamic's default partial provides this; only custom layouts that omit it would need to wire it in manually.

## SVG and GIF

SVG (`image/svg+xml`) and GIF (`image/gif`) sources skip the Glide pipeline entirely. The tag emits a plain `<img>` with the original URL, `width`/`height` from metadata when available, `class`, `loading`, `decoding`, and `aria-hidden="true"` when `alt` is empty. No `<picture>`, no `srcset`, no re-encoding — raster transforms would either produce meaningless output (SVG) or lose animation (GIF).

Glide passthrough params (`blur`, `sharpen`, etc.) are ignored for these sources.

## Art direction

Pass an array of entries via the `sources` parameter. Each entry becomes its own block of `<source>` elements with the given `media` query. Entries earlier in the array win (the browser picks the first matching `<source>`).

**Antlers limitation — sources must be a variable, not an inline literal.** Antlers' expression parser chokes on inline array literals whose string values contain colons (e.g. `(max-width: 768px)`), so you cannot pass `:sources="[{...}]"` directly in a template. Build the array outside the template and pass it by name. The cleanest options:

1. **Blueprint field.** Add a `replicator` or `grid` field called `image_sources` with `src`, `media`, `sizes`, and `ratio` subfields, then:
   ```antlers
   {{ responsive_image :src="hero_desktop" :sources="image_sources" }}
   ```

2. **Template variable via a view composer, augmenter, or controller.** Share a `hero_sources` array from PHP and reference it the same way:
   ```antlers
   {{ responsive_image :src="hero_desktop" :sources="hero_sources" }}
   ```

Each entry accepts `src` (required), `media`, `sizes`, and `ratio`. The last entry's `src` is used as the `<img>` fallback when no breakpoint matches.

## Config

`config/responsive-images.php`:

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

**`device_sizes` vs `image_sizes`.** Device sizes cover full-width images at common device breakpoints. Image sizes cover small images (thumbnails, icons). The final srcset pool is their union, deduped, sorted, and capped at the source image's intrinsic width — the browser then picks the best candidate based on `sizes`.

**Format quality.** AVIF defaults to 50, WebP to 75, fallback to 82. Lower values ship smaller bytes; tune per project.

**Placeholder integration with `daun/statamic-placeholders`.** If you install the [`daun/statamic-placeholders`](https://github.com/daun/statamic-placeholders) addon, its placeholder data (ThumbHash, BlurHash, or Average color — whichever you've configured on the asset's `placeholder` field) is auto-detected and used in preference to the built-in Glide LQIP. When the asset has no placeholder data or when `src` is a raw URL, we silently fall back to the Glide LQIP — output shape is unchanged (still a base64 data URI on `background-image`). Provider choice lives entirely in that addon; we don't expose a provider knob, since mismatching our override against the blueprint's `placeholder_type` would silently miss and fall back. Disable the integration by setting `placeholder.statamic_placeholders.enabled` to `false`.

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
- **Color profiles are normalized to sRGB** on the Imagick driver with the `lcms` delegate. Source images in Adobe RGB, Display P3, or CMYK are color-managed (not blindly tagged) so the browser gets true sRGB output. The manipulator silently no-ops on GD, non-Imagick drivers, and Imagick builds without `lcms` — delivery continues unchanged in those cases. Confirm `lcms` availability with `convert -list configure | grep DELEGATES`. **After upgrading, clear `storage/statamic/glide`** so existing cached transforms get regenerated with the profile applied.

## License

MIT
