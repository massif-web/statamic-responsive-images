# Improve With Peak — Design

**Date:** 2026-04-17
**Status:** Proposed
**Branch:** `improve-with-peak`
**Addon:** `Massif\ResponsiveImages` for Statamic 6

## Purpose

Adopt a focused set of behaviors from the Studio 1902 Peak `Picture` tag (checked in at `claude-instructions/peak-picture-tag.php`) into the existing `{{ responsive_image }}` addon, without regressing the places where Massif is already more correct than Peak.

Net goal: tighter DX (shorter tag alias, field-wildcard syntax), broader source handling (SVG/GIF passthrough, array unwrap), genuinely useful performance affordances (preload), and a controlled set of Glide knobs — without bloating the tag class or tying output to a specific CSS framework.

## Goals

- Add a short configurable tag alias (default `pic`) alongside the canonical `responsive_image`.
- Support `{{ responsive_image:field_name }}` / `{{ pic:field_name }}` wildcard form so fields can be rendered without an explicit `src=`.
- Handle SVG and GIF sources cleanly: bypass Glide, emit a bare `<img>`.
- Unwrap array-shaped `src` values (common Statamic asset-field augmentation).
- Add `preload="true"` that pushes a `<link rel="preload" as="image" imagesrcset=…>` onto the Antlers `head` stack.
- Add `--focal-point` CSS variable alongside the existing `object-position` inline style.
- Add whitelisted Glide passthrough knobs (`blur`, `sharpen`, etc.) and a `quality` / `formats` override at the tag level.
- Emit `aria-hidden="true"` on `<img>` elements when `alt` is empty.
- Flip the default `sizes` to a layout-aware default matching common content-constrained layouts.

## Non-Goals

- Auto-appending periods to alt text (opinionated; editors disagree).
- Baking Tailwind class strings (`object-cover w-full h-full …`) into the addon — users handle their own classes.
- A `cover` / `contain` shortcut param — skipped; belongs in user CSS.
- Runtime HTML rewriting to inject preload links without an Antlers `head` stack.
- Per-breakpoint preload links (`<link media=…>`); preload targets the canonical `<img>`'s top format.
- Copying Peak's redundant `<source type="image/jpeg">` for the fallback format in non-art-directed mode — Massif's `<img srcset>` already covers this.
- Copying Peak's intrinsic-dimension handling — Peak emits raw asset `width`/`height` even when cropped, which is a CLS bug. Massif's ratio-aware dimension math stays.
- Backwards compatibility with the old `'100vw'` default for `default_sizes`; this is a documented breaking change.

## Approach

**Targeted extraction.** Add two new small classes for genuinely separate concerns; extend existing classes for everything else. Two new files, five modified files, no new abstractions invented for things that are param-level.

## File Layout

**New files:**

- `src/Tags/Pic.php` — three-line subclass of `ResponsiveImage`; `$handle` assigned from `config('responsive-images.tag_alias')` at boot.
- `src/View/Preloader.php` — one job: format a `<link rel="preload" …>` string and push it onto Statamic's `head` stack via `StackReplacementManager::pushStack`.
- `src/View/PassthroughRenderer.php` — emits a bare `<img>` for SVG/GIF sources and for `$meta->failed` recovery. Replaces the inline `renderBareImg()` currently in `ResponsiveImage.php`.

**Modified files:**

- `src/Tags/ResponsiveImage.php` — new params (`preload`, `quality`, `formats`, passthrough filters), new `aria-hidden` logic, focal-point CSS variable emit, `wildcard()` method, passthrough routing for SVG/GIF.
- `src/Image/ImageResolver.php` — array unwrap: if input is a non-empty array, take the first element and recurse (max one level).
- `src/Image/UrlBuilder.php` — optional `array $extras = []` parameter merged into the Glide params after core keys, whitelist-filtered.
- `src/View/PictureRenderer.php` — emit both `--focal-point: X% Y%` and `object-position: X% Y%` when focal point is present; add `aria-hidden="true"` when alt is empty.
- `src/ServiceProvider.php` — register `Pic`, bind `Preloader` and `PassthroughRenderer` as singletons, set `Pic::$handle` from config before `parent::register()` runs.

**Config additions (`config/responsive-images.php`):**

```php
'tag_alias' => 'pic',   // null disables

'default_sizes' => '(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw', // was '100vw'

'preload' => [
    'auto_eager'    => true,   // preload=true also sets loading="eager"
    'auto_priority' => true,   // preload=true also sets fetchpriority="high"
],
```

## Tag API — New Parameters

| Param | Type | Behavior |
|---|---|---|
| `preload` | bool | Push a `<link rel="preload" as="image" imagesrcset=… imagesizes=… type=… fetchpriority="high">` onto the `head` stack using the highest-priority enabled format. |
| `quality` | int | Uniform quality override across all formats for this render. Falls back to per-format config quality. |
| `formats` | csv / array | e.g. `"avif,webp,fallback"`. Overrides which formats are emitted. Invalid entries dropped silently; empty list → config defaults. |
| `bg` | string | Glide `bg` passthrough. |
| `blur` | int | Glide `blur` passthrough (does not conflict with LQIP blur — different image). |
| `brightness` | int | Glide `brightness` passthrough. |
| `contrast` | int | Glide `contrast` passthrough. |
| `filter` | string | Glide `filter` passthrough. |
| `flip` | string | Glide `flip` passthrough. |
| `gamma` | float | Glide `gamma` passthrough. |
| `orient` | int/string | Glide `orient` passthrough. |
| `pixelate` | int | Glide `pixelate` passthrough. |
| `sharpen` | int | Glide `sharpen` passthrough. |

**Wildcard form.** Adding `public function wildcard(string $tag): string` to `ResponsiveImage`. Reads `src` from `$this->context->value($tag)`, then delegates to the same `renderForImage()` path as `index()`. `Pic` inherits this.

**Alias `{{ pic … }}`.** Registered as a second tag class. Shares all behavior with `responsive_image`, including wildcard form (`{{ pic:hero }}`).

## Preloader

**Responsibility.** Given an already-built srcset, sizes, and MIME type, format a `<link>` and push it onto the `head` stack.

**Interface:**
```php
class Preloader
{
    public function push(string $srcset, string $sizes, string $mimeType): void;
}
```

**Rendered markup:**
```html
<link rel="preload" as="image"
      imagesrcset="…"
      imagesizes="…"
      type="image/avif"
      fetchpriority="high">
```

**Format selection.** Iterates `['avif', 'webp', 'fallback']` and picks the first enabled in the config (or the first listed in a per-tag `formats` override). Uses that format's already-computed srcset from the current render, so Glide URLs match what `<picture>` will request — same cache key, no extra work.

**Ergonomic auto-sets.** When `preload="true"`:
- If `config('responsive-images.preload.auto_eager')` is true AND no explicit `loading` param was passed, `loading` flips from `lazy` to `eager`.
- If `config('responsive-images.preload.auto_priority')` is true AND no explicit `fetchpriority` param was passed, `fetchpriority` flips from `auto` to `high`.
- Explicit user values always win.

**Caveat.** Requires `{{ stack name="head" }}` in the site layout. Missing stack = Statamic silently discards the push. Documented in README under a "Preload" section.

**Out of scope for v1.** Per-breakpoint preload (`<link media=…>` for art-direction) — would require iterating `sources` and emitting multiple links. Documented as a known limitation; the preload always targets the primary `src`.

## PassthroughRenderer

**Responsibility.** Emit a bare `<img>` tag for sources that shouldn't flow through Glide:
- SVG (`image/svg+xml`) — vector, no raster pipeline.
- GIF (`image/gif`) — re-encoding loses animation.
- Failed metadata reads (`$meta->failed`) — recovery path; use original URL.

**Interface:**
```php
class PassthroughRenderer
{
    public function render(ResolvedImage $image, array $params): string;
}
```

**Attributes emitted:** `src`, `alt`, `width` (if known), `height` (if known), `loading`, `decoding`, `class`, `aria-hidden="true"` when alt is empty. No `srcset`, no `sizes`, no `<picture>` wrapper.

**Glide passthrough params ignored** in this mode — filters like `blur`/`sharpen` require raster pipeline. Documented.

## ImageResolver — array unwrap

Before the existing type checks, add:

```php
if (is_array($src)) {
    if ($src === []) {
        return null;
    }
    // Take first element. More elements = likely template error.
    if (count($src) > 1) {
        Log::debug('[responsive_image] array src has multiple elements; using first', [
            'count' => count($src),
        ]);
    }
    return $this->resolve($src[0]);
}
```

The recursive call handles a nested wrapper (e.g. `[[$asset]]`) by re-entering the resolver. Anything that doesn't eventually resolve to an asset / asset-like object / URL string returns null via the existing type branches — no special loop detection is needed because the array branch always strictly reduces input depth.

## UrlBuilder — `$extras` passthrough

**Signature change:**

```php
public function build(
    ResolvedImage $image,
    int $width,
    string $format,
    int $quality,
    ?int $height = null,
    ?string $fit = null,
    array $extras = [],
): string
```

**Whitelist** (class constant):
```php
private const EXTRAS_WHITELIST = [
    'bg', 'blur', 'brightness', 'contrast', 'filter',
    'flip', 'gamma', 'orient', 'pixelate', 'sharpen',
];
```

**Semantics:**
- `$extras` keys not in the whitelist are dropped silently.
- Numeric-only keys (`blur`, `brightness`, `contrast`, `gamma`, `pixelate`, `sharpen`): non-numeric values dropped.
- `$extras` is merged into the Glide params **after** the core keys (`w`, `h`, `q`, `fit`, `fm`), so user input cannot override ratio/format math.

## Focal Point — CSS variable + inline style

`PictureRenderer` currently emits `style="object-position: X% Y%"`. When a focal point is present, emit both:

```html
style="--focal-point: 50% 30%;object-position: 50% 30%"
```

Zero cost for consumers who don't use the CSS variable. Tailwind users can reference it (`object-position: var(--focal-point)`) or ignore it.

## Alt Handling

No change to how alt is resolved. New behavior: `aria-hidden="true"` is emitted whenever the final resolved `alt` is an empty string, on both the primary `<picture>`'s `<img>` and the `PassthroughRenderer`'s `<img>`. Existing warning log for missing alt still fires.

Auto-appending periods to alt (Peak's `ensureEndsWithPeriod`) is **not** adopted.

## Default `sizes` — Breaking Change

The config default flips from `'100vw'` to `'(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw'`.

**Rationale.** The Peak-style default reflects realistic content-constrained layouts and ships smaller bytes on desktop. `100vw` is safe-but-wasteful.

**Migration.** Users who relied on `100vw` behavior republish the config and set `'default_sizes' => '100vw'`. CHANGELOG entry under a "Breaking" heading.

## Error Handling

| Case | Behavior |
|---|---|
| `src` = `[$asset, ...]` | Resolver unwraps first element; logs debug when >1 element. |
| `src` = `[]` | Returns null; tag renders nothing. |
| `{{ pic:field }}` where `field` is missing from context | Warning logged, renders nothing. |
| `formats="xyz,avif"` invalid entry | `xyz` dropped silently. Empty result → config defaults. |
| `preload="true"` but no format enabled | Preloader returns early; no link pushed. Debug log. |
| `preload="true"` but layout has no head stack | Statamic silently discards; documented caveat. |
| Passthrough filter with invalid value (e.g. `blur="abc"`) | Whitelist drops; no exception. |
| SVG/GIF with missing alt | Existing warning fires; `aria-hidden="true"` emitted. |
| `$meta->failed` | `PassthroughRenderer` emits bare `<img src={original_url}>`. |

## Testing

**New unit tests:**

- `tests/Unit/PreloaderTest.php` — pushes correctly-formatted `<link>`; no-ops when no format provided; escapes srcset/sizes; selects highest-priority enabled format.
- `tests/Unit/PassthroughRendererTest.php` — renders bare `<img>` with correct attrs; `aria-hidden` on empty alt; no srcset/sizes; honors class / loading / decoding; handles missing intrinsic dimensions.
- `tests/Unit/UrlBuilderExtrasTest.php` — whitelisted keys pass through; non-whitelisted dropped; invalid numeric values dropped; extras cannot override `w`/`h`/`q`/`fit`/`fm`.

**Updated unit tests:**

- `tests/Unit/ImageResolverTest.php` — add: `[$asset]` resolves; `[]` returns null; `[null]` returns null; `[$asset, $other]` resolves first + logs debug.
- `tests/Unit/PictureRendererTest.php` — add: focal point emits both CSS variable and `object-position`; empty alt emits `aria-hidden`.

**New feature tests (in `tests/Feature/ResponsiveImageTagTest.php`):**

- SVG src → bare `<img>`, no `<picture>`.
- GIF src → bare `<img>`, no `<picture>`.
- `preload="true"` with AVIF enabled → head stack contains one `<link>` with AVIF srcset + `type="image/avif"`.
- `preload="true"` with AVIF disabled → link uses WebP.
- `preload="true"` → `<img>` gets `loading="eager"` and `fetchpriority="high"` by default.
- `preload="true" loading="lazy"` → user value wins.
- `{{ responsive_image:hero }}` with `hero` in context → resolves.
- `{{ pic src=… }}` → equivalent output to `{{ responsive_image src=… }}`.
- `{{ pic:hero }}` → equivalent to `{{ responsive_image:hero }}`.
- Tag alias `null` → `{{ pic … }}` unregistered.
- `formats="webp,fallback"` → only WebP `<source>` + fallback `<img>`, no AVIF `<source>`.
- `quality="50"` → all srcset URLs include `q=50`.
- `blur="20"` + `sharpen="5"` → URLs include both.
- Array `src` → resolves.
- Empty alt → `aria-hidden="true"` on `<img>`.
- Focal point set → inline style contains both `--focal-point:` and `object-position:`.

**Regression:**
- Existing default-sizes assertions updated to the new default value.

**Explicitly not tested:**
- Preload `<link>` actually reaching rendered HTML — covered by stack-push assertion.
- Glide cache invalidation for passthrough params — delegated to Glide library.

## Documentation Updates

**README.md:**
- New "Preload" section covering the `preload="true"` param, the required `{{ stack name="head" }}` in the layout, the auto-eager / auto-priority behavior, and the format-selection rule.
- New "Tag alias" section covering `{{ pic … }}` and the `tag_alias` config key.
- New "Wildcard form" section covering `{{ responsive_image:field }}` / `{{ pic:field }}`.
- Expand the parameter table with `preload`, `quality`, `formats`, and the Glide passthrough knobs.
- New "SVG and GIF" section noting passthrough behavior.
- Update the default `sizes` documentation to reflect the new layout-aware default.

**CHANGELOG.md (Unreleased):**
- **Breaking:** `default_sizes` default flipped from `'100vw'` to layout-aware.
- **Added:** `pic` tag alias (configurable via `tag_alias`).
- **Added:** `{{ tag:field }}` wildcard form.
- **Added:** SVG / GIF passthrough.
- **Added:** Array `src` unwrap.
- **Added:** `preload="true"` + auto-eager / auto-priority.
- **Added:** `quality`, `formats`, and Glide passthrough params (`blur`, `sharpen`, etc.).
- **Added:** `--focal-point` CSS variable on focal-point images.
- **Added:** `aria-hidden="true"` on `<img>` with empty alt.
- **Config:** `'tag_alias'`, `'preload'` block.

## Out-of-Scope / Future

- Per-breakpoint preload for art-directed images.
- Middleware-level auto-injection of preload links without a head stack.
- Per-format per-tag quality overrides (`quality_avif=…`).
- Animation-preserving GIF → WebP/AVIF encoding.
