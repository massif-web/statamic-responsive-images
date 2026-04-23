# Changelog

## Unreleased

### Breaking
- **`Massif\ResponsiveImages\Tags\Pic` has moved to `Massif\ResponsiveImages\Aliases\Pic`.** Internal class; unlikely to affect users. Branch is unreleased.
- **Default `sizes` flipped** from `'100vw'` to a layout-aware default: `'(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw'`. Images served on desktop are now ~640px wide by default, a meaningful bandwidth win on typical content-constrained layouts. Users who relied on the old behavior should publish the config and set `'default_sizes' => '100vw'`.

### Fixed
- **`tag_alias=null` now correctly disables the `pic` tag.** Previously the `pic` tag remained registered even when the alias was explicitly disabled, because Statamic's `AddonServiceProvider::bootTags()` auto-discovers every class in `src/Tags/`. `Pic` has been relocated to `src/Aliases/Pic.php` (namespace `Massif\ResponsiveImages\Aliases`) so it's registered exclusively via the service provider's conditional logic — present only when `tag_alias` is a non-empty, non-`responsive_image` string.
- **Multi-asset field sources now resolve.** `ImageResolver::resolve()` previously handled arrays, `Asset` instances, and duck-typed asset objects, but fell through on `Statamic\Assets\OrderedQueryBuilder` (the runtime type of a `max_files>1` asset field bound to `:src`), producing an "unresolvable src" log and no output. The resolver now collapses any non-Asset `Traversable` or `Illuminate\Contracts\Support\Arrayable` to its first element, mirroring the existing array-src unwrap behavior. Collections, iterators, and Statamic query builders all resolve.
- **Metadata read failures now log a warning on both the `getimagesize()` false-return and exception paths.** Previously, unreadable-but-non-throwing sources (missing files, non-images) silently returned an `ImageMetadata::failed()` sentinel with no log trail. Both paths now emit `Log::warning('[responsive_image] metadata read failed', …)` with a `reason` field (`'unreadable'` or `'exception'`) differentiating the two, giving operators a single grep needle.
- **Public-relative URLs now resolve for metadata reads.** `MetadataReader::read()` previously passed URLs like `/images/foo.jpg` straight to `getimagesize()`, which treats them as filesystem paths and fails — causing the tag to short-circuit to a bare `<img>` with no srcset. Root-relative URLs are now resolved through `public_path()` before the read. URLs with a scheme (`http://`, `https://`) and absolute filesystem paths pass through unchanged.

### Changed
- **Metadata cache hardening.** `MetadataReader::read()` now wraps its full
  body in a `\Exception` catch and returns an `ImageMetadata::failed()`
  sentinel on any read error — corrupt files, missing assets, driver
  exceptions. The sentinel is cached with a short TTL so a broken asset
  referenced from a high-traffic page can no longer cause per-request
  re-reads. On a failed read, the `{{ responsive_image }}` tag renders a
  bare `<img src="…" alt="…" loading="lazy" decoding="async">` (no
  `<picture>`, no srcset, no width/height) instead of garbage markup.
- `Metadata::for()` now returns a readonly `ImageMetadata` value object
  instead of an array. Consumers access `$meta->width`, `$meta->height`,
  `$meta->mime`, and `$meta->failed`.
- Successful metadata reads are cached with a bounded TTL (default 90
  days) instead of `rememberForever`, preventing unbounded cache growth as
  assets are replaced over time.
- Metadata cache wire format is a plain array (not the `ImageMetadata`
  DTO). Laravel 12's `cache.serializable_classes` config — when set to
  `false` or a restrictive allow-list — causes `unserialize()` to return
  `__PHP_Incomplete_Class` for unknown classes, which would break the
  cache round-trip and trigger a re-read + re-write on every request.
  Storing as an array sidesteps the allow-list entirely. The public API
  of `Metadata::for()` still returns an `ImageMetadata` instance — only
  the on-cache representation changed.

### Config
- **New:** `'tag_alias' => 'pic'` — short alias tag handle.
- **New:** `'preload' => ['auto_eager' => true, 'auto_priority' => true]` — ergonomic auto-sets when `preload="true"` is used.
- **Changed (breaking):** `'default_sizes'` default — see Breaking above.
- **Breaking:** `config/responsive-images.php` `cache.ttl` is removed.
  Replaced by `cache.metadata_ttl` (default `7_776_000` — 90 days) and
  `cache.sentinel_ttl` (default `60` — seconds to cache failed reads).
  Republish the config if you previously customized `cache.ttl`.

### Added
- **`{{ pic }}` short alias** alongside `{{ responsive_image }}`, with a configurable handle via `config('responsive-images.tag_alias')` (default `'pic'`, `null` disables).
- **Wildcard form** `{{ responsive_image:field }}` / `{{ pic:field }}` — resolves `src` from the template context.
- **SVG / GIF passthrough** — `image/svg+xml` and `image/gif` sources bypass Glide and render a plain `<img>` with intrinsic dimensions, `class`, `loading`, `decoding`, and `aria-hidden` when alt is empty.
- **Array `src` unwrap** — fields augmented to `[$asset]` (common Statamic behavior) now resolve correctly.
- **`preload="true"` param** — pushes a `<link rel="preload" as="image">` onto the Antlers `head` stack for above-the-fold images. Works out of the box on a stock Statamic layout — the default head partial already renders `@stack('head')`. Auto-sets `loading="eager"` and `fetchpriority="high"` on the `<img>` by default (togglable via `config.preload.auto_eager` / `auto_priority`).
- **`quality` and `formats` tag params** — per-render quality override; per-render restriction of emitted formats (e.g. `formats="webp,fallback"`).
- **Glide passthrough filter params** — `bg`, `blur`, `brightness`, `contrast`, `filter`, `flip`, `gamma`, `orient`, `pixelate`, `sharpen`. Whitelisted in `UrlBuilder` to prevent arbitrary method invocation.
- **`--focal-point` CSS variable** emitted alongside `object-position: …` on images with a focal point. Tailwind users can reference `var(--focal-point)`; non-Tailwind users still get the direct inline style.
- **`aria-hidden="true"`** on `<img>` elements with an empty `alt`, including SVG / GIF passthrough.
- ICC color profile normalization for transformed images. A custom Glide
  manipulator (`Massif\ResponsiveImages\Glide\ColorProfile`) now runs after
  all resize/crop/orientation steps and converts the image's source
  colorspace to sRGB via ImageMagick's `lcms` delegate. Fixes washed-out
  output for Adobe RGB, Display P3, and CMYK source images.

  The sRGB profile lives at
  `resources/icc/sRGB_IEC61966-2-1_black_scaled.icc` inside the addon
  package. If the file or the `lcms` delegate is missing, or the image
  driver is not Imagick, the manipulator silently no-ops and delivery
  continues unchanged.

### Upgrade notes
- **Clear `storage/statamic/glide` after upgrading.** Existing cached
  transforms were generated without the profile and will keep serving
  stale output until the cache is cleared.
