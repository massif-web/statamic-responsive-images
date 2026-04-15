# Changelog

## Unreleased

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
