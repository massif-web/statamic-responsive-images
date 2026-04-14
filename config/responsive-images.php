<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Widths
    |--------------------------------------------------------------------------
    |
    | The default srcset widths generated when no widths param is provided.
    |
    */
    'widths' => [320, 640, 960, 1280, 1920],

    /*
    |--------------------------------------------------------------------------
    | Default Sizes
    |--------------------------------------------------------------------------
    |
    | The default sizes attribute value for <img> and <source> elements.
    |
    */
    'sizes' => '100vw',

    /*
    |--------------------------------------------------------------------------
    | Format Support
    |--------------------------------------------------------------------------
    |
    | Control which modern formats are generated. Disable AVIF if your Glide
    | driver (e.g. GD) does not support it.
    |
    */
    'avif' => true,
    'webp' => true,

    /*
    |--------------------------------------------------------------------------
    | Format Quality
    |--------------------------------------------------------------------------
    |
    | Quality settings per format (1–100).
    |
    */
    'quality' => [
        'avif' => 60,
        'webp' => 75,
        'jpg'  => 85,
        'png'  => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Minimum Width Threshold for Modern Formats
    |--------------------------------------------------------------------------
    |
    | Skip AVIF/WebP generation for widths below this threshold (px).
    |
    */
    'modern_format_min_width' => 300,

    /*
    |--------------------------------------------------------------------------
    | Placeholder / LQIP
    |--------------------------------------------------------------------------
    |
    | Low-quality image placeholder settings. When enabled, a tiny inline
    | image is embedded as a background for perceived performance.
    |
    */
    'placeholder' => [
        'enabled' => false,
        'width'   => 32,
        'quality' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Loading Strategy
    |--------------------------------------------------------------------------
    |
    | Sets the default `loading` attribute on <img>. Use "lazy" for most
    | images and "eager" for above-the-fold hero images.
    |
    */
    'loading' => 'lazy',

    /*
    |--------------------------------------------------------------------------
    | Default Decoding
    |--------------------------------------------------------------------------
    */
    'decoding' => 'async',

    /*
    |--------------------------------------------------------------------------
    | Presets
    |--------------------------------------------------------------------------
    |
    | Named presets that bundle widths, sizes, and other options.
    |
    | Example:
    |   'hero' => [
    |       'widths' => [768, 1280, 1920],
    |       'sizes'  => '100vw',
    |   ],
    |
    */
    'presets' => [],

];
