<?php

namespace Massif\ResponsiveImages;

use Statamic\Providers\AddonServiceProvider;
use Massif\ResponsiveImages\Image\ImageResolver;
use Massif\ResponsiveImages\Image\Metadata;
use Massif\ResponsiveImages\Image\MetadataReader;
use Massif\ResponsiveImages\Image\Placeholder;
use Massif\ResponsiveImages\Image\SrcsetBuilder;
use Massif\ResponsiveImages\Image\UrlBuilder;
use Massif\ResponsiveImages\Tags\ResponsiveImage;
use Massif\ResponsiveImages\View\PictureRenderer;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [
        ResponsiveImage::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(SrcsetBuilder::class);
        $this->app->singleton(MetadataReader::class);
        $this->app->singleton(PictureRenderer::class);

        $this->app->singleton(ImageResolver::class, fn () => new ImageResolver());
        $this->app->singleton(UrlBuilder::class, fn () => new UrlBuilder());

        $this->app->singleton(Metadata::class, function ($app) {
            $config = $app['config']->get('responsive-images');

            return new Metadata(
                $app->make(MetadataReader::class),
                $app['cache']->store($config['cache']['store'] ?? null),
                $config['cache']['prefix'] ?? 'respimg',
                $config['cache']['ttl'] ?? null,
            );
        });

        $this->app->singleton(Placeholder::class, function ($app) {
            $config = $app['config']->get('responsive-images');

            return new Placeholder(
                $app['cache']->store($config['cache']['store'] ?? null),
            );
        });
    }
}
