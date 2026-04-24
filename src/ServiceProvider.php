<?php

namespace Massif\ResponsiveImages;

use League\Glide\Server;
use Massif\ResponsiveImages\Glide\ColorProfile;
use Massif\ResponsiveImages\Image\ImageResolver;
use Massif\ResponsiveImages\Image\Metadata;
use Massif\ResponsiveImages\Image\MetadataReader;
use Massif\ResponsiveImages\Image\Placeholder;
use Massif\ResponsiveImages\Image\ResolvedImage;
use Massif\ResponsiveImages\Image\SrcsetBuilder;
use Massif\ResponsiveImages\Image\UrlBuilder;
use Massif\ResponsiveImages\Aliases\Pic;
use Massif\ResponsiveImages\Tags\ResponsiveImage;
use Massif\ResponsiveImages\View\PassthroughRenderer;
use Massif\ResponsiveImages\View\PictureRenderer;
use Massif\ResponsiveImages\View\Preloader;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [];

    public function __construct($app)
    {
        parent::__construct($app);

        $this->tags = [ResponsiveImage::class];

        $alias = (string) ($app['config']->get('responsive-images.tag_alias') ?? '');
        $alias = trim($alias);

        if ($alias !== '' && $alias !== 'responsive_image') {
            Pic::$handle = $alias;
            $this->tags[] = Pic::class;
        }
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(SrcsetBuilder::class);
        $this->app->singleton(MetadataReader::class);
        $this->app->singleton(PictureRenderer::class);
        $this->app->singleton(PassthroughRenderer::class);
        $this->app->singleton(Preloader::class);

        $this->app->singleton(ImageResolver::class, fn () => new ImageResolver());
        $this->app->singleton(UrlBuilder::class, fn () => new UrlBuilder());

        $this->app->singleton(Metadata::class, function ($app) {
            $config = $app['config']->get('responsive-images');

            return new Metadata(
                $app->make(MetadataReader::class),
                $app['cache']->store($config['cache']['store'] ?? null),
                $config['cache']['prefix'] ?? 'respimg',
                (int) ($config['cache']['metadata_ttl'] ?? 7_776_000),
                (int) ($config['cache']['sentinel_ttl'] ?? 60),
            );
        });

        $this->app->singleton(Placeholder::class, function ($app) {
            $config = $app['config']->get('responsive-images');

            $integration = $config['placeholder']['statamic_placeholders'] ?? [];
            $externalResolver = null;

            if (
                ! empty($integration['enabled'])
                && class_exists(\Daun\StatamicPlaceholders\Facades\Placeholders::class)
            ) {
                $externalResolver = function (ResolvedImage $image): ?string {
                    if (! $image->isAsset() || $image->asset === null) {
                        return null;
                    }

                    return \Daun\StatamicPlaceholders\Facades\Placeholders::uri($image->asset);
                };
            }

            return new Placeholder(
                cache: $app['cache']->store($config['cache']['store'] ?? null),
                externalResolver: $externalResolver,
            );
        });
    }

    public function bootAddon(): void
    {
        $profilePath = __DIR__.'/../resources/icc/sRGB_IEC61966-2-1_black_scaled.icc';

        $this->app->resolving(Server::class, function (Server $server) use ($profilePath) {
            $api = $server->getApi();
            $manipulators = $api->getManipulators();
            $manipulators[] = new ColorProfile($profilePath);
            $api->setManipulators($manipulators);
            $server->setApi($api);
        });
    }
}
