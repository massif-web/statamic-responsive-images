# Manual Test Plan — `improve-with-peak` Branch

## Context

The `improve-with-peak` branch landed 14 tasks extending the `Massif\ResponsiveImages` Statamic addon with features ported from Peak's `Picture` tag. PHPUnit suite is 97/97 green, but unit/feature tests can't verify: rendered HTML in a real layout, head-stack preload links, browser format negotiation, config-variation smoke, and cross-feature interactions. This checklist is what to click through manually before merging.

**Known gap surfaced during the session:** `MetadataReader::read()` (`src/Image/MetadataReader.php:20`) calls `getimagesize($url)` directly. A public-relative URL like `/images/a-peak.jpg` is treated as a filesystem path, fails, and the tag short-circuits to `PassthroughRenderer` (bare `<img>`, no srcset). Tests below use **asset references** except where explicitly testing the passthrough fallback. A follow-up task can resolve public URLs via `public_path()` but is out of scope for this branch.

## Prerequisites

1. Statamic 6 app with the addon installed via `composer require` (path or versioned).
2. An asset container with:
   - `landscape.jpg` — large JPEG (≥1600px wide), no focal point
   - `portrait_focal.jpg` — JPEG with a focal point set in the CP (e.g. `75-25-3`)
   - `logo.svg` — SVG with declared width/height
   - `spinner.gif` — animated GIF
3. A layout template with `{{ stack name="head" }}` inside `<head>`.
4. An entry with these fields: `hero` (asset, single), `gallery` (asset, multiple, max=3).
5. Clean caches between config-variation tests: `php artisan statamic:stache:clear && php artisan view:clear`.

## Test cases

Each case: Antlers snippet → expected observable behavior.

### Core rendering (regression)

**1. Base tag still emits `<picture>`**

```antlers
{{ pic :src="hero" alt="Base" }}
```

- `<picture>` with `<source type="image/avif">`, `<source type="image/webp">`, `<img>`
- srcset has multiple widths (`... 640w, ... 828w, ...`)
- `sizes="(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw"` (new default)
- `<img>` has `width` and `height` matching intrinsic
- No `aria-hidden`, no `--focal-point`
- URLs in `/img/` namespace

**2. Short alias parity** — `{{ responsive_image ... }}` and `{{ pic ... }}` produce byte-identical output (except alt).

**3. Art direction still works** (regression — no changes to this path)

```antlers
{{ pic :src="hero" alt="AD" :sources="[
  { src: hero_mobile, media: '(max-width: 768px)' },
  { src: hero }
] }}
```

- One `<source>` per entry × format, media queries correct.

**4. Figure + caption + class routing** (regression)

```antlers
{{ pic :src="hero" alt="X" class="hero" figure="true" caption="Gold hour." }}
```

- `<figure class="hero">` wraps `<picture>`, `<figcaption>Gold hour.</figcaption>` inside.
- Without `figure`: class lands on the `<img>` instead.

### New features

**5. Wildcard form** — `{{ pic:hero }}` and `{{ responsive_image:hero }}` pull `src` from the entry's `hero` field. Same output as (1).

**6. Array-src unwrap** — when the field is augmented to `[$asset]`:

```antlers
{{ pic:gallery alt="First of many" }}
```

- Renders the first item as a normal `<picture>`. If the gallery has >1 item, tail the log for one `[responsive_image] array src has multiple elements` debug entry.

**7. SVG passthrough**

```antlers
{{ pic :src="logo" alt="Logo" }}
```

- Plain `<img src="..." alt="Logo" loading="lazy" decoding="async">` — NO `<picture>`, NO srcset
- `width`/`height` from asset if SVG declares them; omitted otherwise
- No `aria-hidden` (alt non-empty)

**8. SVG/GIF empty alt → `aria-hidden`**

```antlers
{{ pic :src="logo" alt="" }}
```

- Same passthrough shape PLUS `aria-hidden="true"`
- Warning in `storage/logs/laravel.log`: `[responsive_image] missing alt text`

**9. GIF passthrough** — same shape as SVG (no re-encoding, preserves animation).

**10. Failed-meta fallback**

```antlers
{{ pic src="/does-not-exist.jpg" alt="Broken" }}
```

- Plain `<img src="/does-not-exist.jpg" alt="Broken">` — no width/height (meta failed)
- Log: `[responsive_image] metadata read failed`
- Second request within 60s does NOT re-read (sentinel TTL)

**11. Focal-point dual emission**

With `portrait_focal` having focus `75-25-3`:

```antlers
{{ pic :src="portrait_focal" alt="Tight" ratio="1:1" }}
```

- `<img>` `style=` contains BOTH `--focal-point: 75% 25%` AND `object-position: 75% 25%`.
- Tailwind users can read `var(--focal-point)`; everyone else gets the direct style.

**12. `quality` param**

```antlers
{{ pic :src="hero" alt="Low q" quality="40" }}
```

- Every srcset URL has `q=40` regardless of format.

**13. `formats` param — restrict**

```antlers
{{ pic :src="hero" alt="No AVIF" formats="webp,fallback" }}
```

- `<picture>` has `<source type="image/webp">` + `<img>` only. No AVIF.

**14. `formats` param — fallback only**

```antlers
{{ pic :src="hero" alt="Plain" formats="fallback" }}
```

- `<picture>` contains just the `<img>`. No `<source>`.

**15. Glide passthrough filters**

```antlers
{{ pic :src="hero" alt="Filtered" blur="30" sharpen="20" gamma="1.5" filter="sepia" }}
```

- Every srcset URL and the fallback URL contain `blur=30`, `sharpen=20`, `gamma=1.5`, `filter=sepia`.
- Open the image in a browser — it should actually be blurred and sepia.

**16. Preload — head-stack link**

Layout has `{{ stack name="head" }}` in `<head>`.

```antlers
{{ pic :src="hero" alt="LCP" preload="true" }}
```

- `<head>` contains: `<link rel="preload" as="image" imagesrcset="..." imagesizes="..." type="image/avif" fetchpriority="high">`
- `imagesrcset` matches the AVIF `<source>` srcset exactly
- `<img>` has `loading="eager"` (auto-eager) and `fetchpriority="high"` (auto-priority)

**17. Preload — no head stack in layout**

- Remove `{{ stack name="head" }}`, re-render.
- `<img>` still gets `loading="eager"` + `fetchpriority="high"`, but the preload `<link>` is silently dropped (Statamic discards stack pushes with no matching stack).

**18. Preload — AVIF disabled**

Override `'formats.avif.enabled' => false`:

```antlers
{{ pic :src="hero" alt="WebP LCP" preload="true" }}
```

- Preload `<link>` has `type="image/webp"`.

**19. Preload — explicit `loading` wins**

```antlers
{{ pic :src="hero" alt="Odd" preload="true" loading="lazy" }}
```

- `<img>` has `loading="lazy"` (caller beats auto-eager). Preload `<link>` still pushed.

**20. Preload — explicit `fetchpriority` wins**

```antlers
{{ pic :src="hero" alt="Odd" preload="true" fetchpriority="auto" }}
```

- `<img>` has `fetchpriority="auto"`. Preload `<link>` still pushed.

**21. Auto-eager config disable**

Override `'preload.auto_eager' => false`:

```antlers
{{ pic :src="hero" alt="Lazy LCP" preload="true" }}
```

- `<img>` has `loading="lazy"` (config wins). `fetchpriority="high"` still applies.

**22. Art direction + preload**

```antlers
{{ pic :src="hero" alt="AD LCP" preload="true" :sources="[
  { src: hero_mobile, media: '(max-width: 768px)' },
  { src: hero }
] }}
```

- Preload `<link>` targets the desktop (no-media) source's srcset. Per-breakpoint preload is NOT supported.

### Config variations

**23. Custom `tag_alias`**

Override `'tag_alias' => 'photo'`, clear caches.

- `{{ photo :src="hero" alt="..." }}` renders full `<picture>`.
- `{{ pic :src="hero" alt="..." }}` no longer resolves.

**24. `tag_alias` disabled**

Override `'tag_alias' => null`.

- `{{ pic ... }}` no longer resolves. `{{ responsive_image ... }}` still works.

**25. Default sizes bandwidth check (breaking-change validation)**

On a 1920px desktop viewport, open DevTools → Network, load a page with `{{ pic :src="hero" alt="..." }}`.

- Before this branch (`sizes="100vw"`): the picked image was ~1920w.
- After this branch: the picked image should be in the 640w–828w range (saves significant bandwidth).
- If you need the old behavior: document in CHANGELOG Breaking section that users can override with `sizes="100vw"` per-tag or publish the config and set `default_sizes => '100vw'`.

### Cache behavior

**26. Metadata cache hit**

- First request generates Glide URLs + reads metadata, stores under `respimg:*` keys in the configured store.
- Second request observes cache hit (no new `getimagesize`, no new Glide transform).
- Replace the asset file on disk → mtime changes → cache key changes → fresh metadata read on next request.

### End-to-end smoke

**27. Full page combining everything**

Build one page with:

- A regular `{{ pic }}` with focal point + ratio
- An SVG logo
- A `preload="true"` LCP image above the fold
- An art-directed `<picture>` below the fold

In the browser:

- View source: `<link rel="preload">` in `<head>`, correct `<picture>` markup throughout
- DevTools → Network: viewport-appropriate widths selected; AVIF-capable browsers get AVIF
- DevTools → Lighthouse: LCP image is preloaded (no "lazily loaded LCP" warning)
- DevTools → Computed style on the focal-point image: `object-position` resolves correctly

## Verification

Cross-reference against the Spec Coverage table in `docs/superpowers/plans/2026-04-17-improve-with-peak.md` (lines 2352-2373). Every spec row should map to at least one test above. If a row doesn't, add a test.

## Files referenced

- `src/Tags/ResponsiveImage.php` — main tag, `renderForImage()` dispatch
- `src/Tags/Pic.php` — short alias subclass
- `src/View/PassthroughRenderer.php` — SVG / GIF / failed-meta path
- `src/View/Preloader.php` — head-stack `<link rel="preload">` push
- `src/View/PictureRenderer.php` — `<picture>` + focal-point + aria-hidden
- `src/Image/UrlBuilder.php` — Glide URL construction + extras whitelist
- `src/Image/MetadataReader.php` — the `getimagesize` call that causes the known gap
- `src/ServiceProvider.php` — Pic alias wiring, Preloader/Passthrough singletons
- `config/responsive-images.php` — all config keys
