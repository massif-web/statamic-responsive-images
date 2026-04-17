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
