<?php

namespace Massif\ResponsiveImages;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [
        // ResponsiveImage tag registered in Task 10
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(
            __DIR__ . '/../config/responsive-images.php',
            'responsive-images'
        );
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__ . '/../config/responsive-images.php' => config_path('responsive-images.php'),
        ], 'responsive-images-config');
    }
}
