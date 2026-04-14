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
        'default_fit' => 'crop_focal',
    ],

    'cache' => [
        'store'  => null,
        'ttl'    => null,
        'prefix' => 'respimg',
    ],
];
