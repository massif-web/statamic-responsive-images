# Changelog

## Unreleased

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
- **Breaking:** `config/responsive-images.php` `cache.ttl` is removed.
  Replaced by `cache.metadata_ttl` (default `7_776_000` — 90 days) and
  `cache.sentinel_ttl` (default `60` — seconds to cache failed reads).
  Republish the config if you previously customized `cache.ttl`.

### Added
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
