# Responsive Images Addon — Design

**Date:** 2026-04-14
**Status:** Approved (design phase)
**Addon:** `Massif\ResponsiveImages` for Statamic 6

## Purpose

A production-ready Statamic 6 addon providing a single Antlers tag, `{{ responsive_image }}`, that emits a `<picture>` element with AVIF / WebP / fallback sources, correct intrinsic dimensions, an inline blurred placeholder, and srcset generation driven by a Next.js-inspired `device_sizes` + `image_sizes` config model.

The addon is for Massif's own sites first, written cleanly enough to open-source later.

## Goals

- One tag, minimal required params: `{{ responsive_image :src="image" alt="..." }}` must Just Work.
- Zero image encoding in the PHP request cycle — delegate all transformation to Statamic's Glide pipeline and its disk cache.
- Browser-native format negotiation via `<picture>` + `<source type="image/...">`. No `Accept` header sniffing.
- Prevent CLS via correct `width`/`height` attributes on every render.
- Inline blurred LQIP with no JS runtime and no external dependencies.
- Clean boundaries: each internal class has one responsibility and is unit-testable in isolation.

## Non-Goals

- Client-side JS for lazy loading, blurhash decoding, or format detection.
- Server-side `Accept` header sniffing.
- Alpha-channel detection / format-skip heuristics based on image content.
- Width-threshold gating of modern formats.
- A `preset` param or named bundle system. Antlers partials cover reuse.
- Backwards compatibility with Statamic < 6.

## Addon Structure

```
addons/Massif/ResponsiveImages/
├── composer.json
├── README.md
├── config/responsive-images.php
└── src/
    ├── ServiceProvider.php
    ├── Tags/ResponsiveImage.php        # Antlers tag entry point
    ├── Image/
    │   ├── ImageResolver.php           # src (Asset|path|url) → resolved source + metadata
    │   ├── Metadata.php                # cached {width, height, mime, placeholder}
    │   ├── SrcsetBuilder.php           # picks widths from device_sizes ∪ image_sizes
    │   ├── UrlBuilder.php              # wraps Statamic Glide, per-format params
    │   └── Placeholder.php             # tiny blurred base64 data URI
    └── View/PictureRenderer.php        # builds final <picture>/<figure>/<img> HTML
```

**Namespace:** `Massif\ResponsiveImages`

### Module boundaries

- `ImageResolver` is the **only** class that touches Statamic's asset system directly.
- `Metadata` is the **only** class that reads pixels from disk. All reads are cached.
- `SrcsetBuilder` is pure: `(Metadata, Config, ?int $hintedWidth) → int[]`.
- `UrlBuilder` is pure: `(source, width, format, params) → string`. Wraps Statamic's Glide URL helper.
- `Placeholder` produces a base64 data URI via a single synchronous Glide request on cold cache.
- `PictureRenderer` is the **only** class that emits HTML. Everyone else returns data.
- `ResponsiveImage` (the tag class) is a thin orchestrator: parse params → call the above → return string.

This factoring keeps the tag class small, each unit independently testable, and the rendering layer swappable.

## Tag API

```antlers
{{ responsive_image
    :src="image"
    alt="A descriptive caption"
    sizes="(min-width: 1024px) 50vw, 100vw"
    class="rounded-lg"
    img_class="object-cover"
    loading="lazy"
    decoding="async"
    fetchpriority="auto"
    ratio="16/9"
    fit="crop_focal"
    width="1200"
    height="675"
    figure="true"
    caption="Photo by Someone"
    ratio_wrapper="true"
    placeholder="true"
    :sources="art_direction"
    widths="400,800,1200"
/}}
```

### Required params

- **`src`** — Statamic `Asset` object, asset reference string (`assets::id`), or a plain URL/path.

### Optional params and defaults

| Param | Default | Notes |
|---|---|---|
| `alt` | asset's `alt` meta | Param overrides the asset's alt for this render only; does not mutate the asset. If both param and asset meta are empty, log a warning and render `alt=""`. |
| `sizes` | `"100vw"` | From config `default_sizes` |
| `class` | `null` | Applied to outermost wrapper (`<figure>` if present, else ratio wrapper if present, else `<picture>`) |
| `img_class` | `null` | Always applied to `<img>` |
| `loading` | `"lazy"` | |
| `decoding` | `"async"` | |
| `fetchpriority` | `"auto"` | |
| `ratio` | `null` | Accepts `"16/9"` or `"16:9"`, normalized to float |
| `fit` | depends | `crop_focal` when ratio/crop active, `contain` otherwise |
| `width` | `null` | Explicit intrinsic width override |
| `height` | `null` | Explicit intrinsic height override |
| `figure` | `false` | Wraps output in `<figure>` |
| `caption` | `null` | Populates `<figcaption>` when `figure=true` |
| `ratio_wrapper` | `false` | Wraps `<picture>` in `<div style="aspect-ratio:X/Y">` |
| `placeholder` | config `placeholder.enabled` | Per-call override |
| `sources` | `null` | Antlers array of `{src, media, ratio?, sizes?}` for art direction. Optional. |
| `widths` | derived | Escape hatch; comma-separated ints. If omitted, `SrcsetBuilder` derives from config. |

### Invalid input behavior

- Unresolvable `src` → render empty string, log warning via `Log::warning`, do not throw.
- Missing `alt` and missing asset alt → log warning, render `alt=""`, do not break the page.
- Unknown params → silently ignored (Statamic convention).
- Invalid `ratio` / `widths` format → log warning, fall back to derived defaults.

## Config File

`config/responsive-images.php`:

```php
return [
    // Srcset generation (next/image model)
    'device_sizes' => [640, 750, 828, 1080, 1200, 1920, 2048, 3840],
    'image_sizes' => [16, 32, 48, 64, 96, 128, 256, 384],

    // Default sizes attribute when not provided
    'default_sizes' => '100vw',

    // Fallback <img src> width target
    'fallback_width' => 828,

    // Formats
    'formats' => [
        'avif'     => ['enabled' => true, 'quality' => 50],
        'webp'     => ['enabled' => true, 'quality' => 75],
        'fallback' => ['quality' => 82], // original format (jpg/png)
    ],

    // Placeholder (LQIP): tiny blurred Glide image, inlined as base64
    'placeholder' => [
        'enabled' => true,
        'width'   => 32,
        'blur'    => 40, // Glide blur param
        'quality' => 40,
    ],

    // Glide
    'glide' => [
        'default_fit' => 'crop_focal',
    ],

    // Cache
    'cache' => [
        'store'  => null,     // null = default cache store
        'ttl'    => null,     // null = forever
        'prefix' => 'respimg',
    ],
];
```

All values are publishable via the standard Laravel `vendor:publish` mechanism. No hidden defaults in code.

## Srcset Generation

Following the next/image model:

1. Full pool = `device_sizes ∪ image_sizes`, sorted ascending, deduped.
2. Filter: drop any width greater than the source image's intrinsic width (from `Metadata`). Never upscale.
3. If `widths` param was provided, use it as the pool instead (still capped at intrinsic width).
4. Emit one srcset descriptor per entry: `"{url} {width}w"`.

Each format (AVIF, WebP, fallback) gets its own `<source>` element with the same set of widths but different `fm=` / `q=` query params pointing at Glide.

## Dimension Resolution

For the `<img>` intrinsic `width` / `height`:

- If both `width` and `height` are explicit → use as-is.
- Else if `ratio` provided and one dimension given → compute the other.
- Else if `ratio` provided, no dimensions → `width = max(srcset widths)`, `height = round(width / ratio)`.
- Else → intrinsic values from `Metadata`, optionally scaled if only `width` is given.

When ratio is active, every Glide URL for that render includes `w=X&h=Y&fit={fit}` so the actual rendered bytes match the declared aspect ratio.

## Data Flow (single render)

```
{{ responsive_image :src="image" alt="..." sizes="50vw" ratio="16/9" }}
         │
         ▼
ResponsiveImage::index()
  1. Normalize params (ratio → float, widths → int[], sizes → string)
  2. ImageResolver::resolve($src) → resolved source
  3. Metadata::for($source)  ← cached: {prefix}:{id}:{mtime}
        ├─ width, height, mime
        └─ placeholder data URI (if enabled)
  4. SrcsetBuilder::build($metadata, $config, $hintedSize) → int[]
  5. For each enabled format [avif, webp, fallback]:
        UrlBuilder::build(...) → "/img/asset/...&w=...&fm=webp&q=..."
  6. PictureRenderer::render($sources, $imgData, $wrappers) → HTML
```

### Caching guarantees

- **One metadata read per asset, ever** (until mtime changes). Key: `{prefix}:{id}:{mtime}`. Stored in the configured Laravel cache.
- **Zero image encoding in the PHP request cycle.** `UrlBuilder` only builds URLs; Statamic's Glide controller encodes on first HTTP hit and caches to disk. Subsequent requests are static file serves.
- **Placeholder generated once per asset.** On cold cache, `Placeholder` performs a single synchronous Glide request to produce the tiny blurred image, base64-encodes the result, and stores it alongside metadata.
- **Steady-state render** is pure array manipulation + string building. No I/O.

## Rendering Rules

### Standard output (no art direction)

```html
<picture>
  <source type="image/avif" srcset="/img/... 400w, /img/... 828w, ..." sizes="50vw">
  <source type="image/webp" srcset="/img/... 400w, /img/... 828w, ..." sizes="50vw">
  <img
    src="/img/...&w=828"
    srcset="/img/... 400w, ..."
    sizes="50vw"
    width="1200"
    height="675"
    alt="..."
    class="{img_class}"
    loading="lazy"
    decoding="async"
    fetchpriority="auto"
    style="background-size:cover;background-image:url('data:image/jpeg;base64,...')">
</picture>
```

### `ratio_wrapper="true"`

```html
<div style="aspect-ratio:16/9" class="{class}">
  <picture>...</picture>
</div>
```

The `class` param moves to the outermost wrapper. `img_class` stays on `<img>`.

### `figure="true"`

```html
<figure class="{class}">
  <picture>...</picture>
  <figcaption>{caption}</figcaption>
</figure>
```

### Both

`<figure>` is outermost, ratio wrapper is inside it, `<picture>` is inside that.

### Art direction (`:sources`)

One `<source>` block per art-direction entry, per format, in order: all AVIF, then all WebP, then all fallback, then `<img>`. Browser walks top-to-bottom and picks the first match. The final entry (which carries no `media` query) provides the default srcset.

Each `sources` entry may override `src`, `media`, `ratio`, and `sizes`; it inherits format/quality/placeholder config from the parent tag.

### Placeholder rendering

Inlined as `background-image` on the `<img>` element itself, not a separate element. The browser paints the blurred data URI immediately and replaces it visually when the real image loads. No JS. Disabled via config or `placeholder="false"`.

### Fallback `<img src>`

Fixed at `config.fallback_width` (default 828), in the original format. Only hit when `<picture>` matching fails entirely — vanishingly rare in Statamic 6's target browsers.

### Security

All user-provided strings (`alt`, `caption`, `class`, `img_class`) pass through `e()` / `htmlspecialchars` before rendering. URLs are produced by Statamic's built-in Glide URL helpers, which handle encoding.

## Testing Strategy

Unit tests per class, at the boundaries:

- `SrcsetBuilder`: pure function, table-driven tests covering widths filtering, escape hatch, source-smaller-than-pool edge cases.
- `UrlBuilder`: assert query string contents for each format.
- `Metadata`: cache hit/miss behavior with a fake cache store.
- `Placeholder`: verify data URI shape and that Glide is hit exactly once per (asset, mtime).
- `ResponsiveImage` (tag): integration tests with a fixture asset, snapshot the rendered HTML across representative param combinations (standard, ratio, figure, ratio_wrapper, art direction).

## README Outline

1. Installation (`composer require`, `php artisan vendor:publish`)
2. Quickstart — `{{ responsive_image :src="image" alt="..." }}`
3. Parameter reference table
4. Config reference
5. Art direction example
6. Performance notes (Glide cache, cache invalidation, zero request-cycle encoding)
7. Caveats — AVIF support across Glide drivers, placeholder cold-start cost, image_sizes/device_sizes tuning

## Open Questions

None at design-close. Ambiguities will be resolved in favor of the simplest robust solution during implementation.
