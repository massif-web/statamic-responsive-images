<?php

namespace Massif\ResponsiveImages\Image;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Statamic\Imaging\GlideManager;
use Statamic\Imaging\ImageGenerator;

class Placeholder
{
    /** @var Closure|null */
    private $fetcher;

    /** @var Closure|null */
    private $externalResolver;

    /** @var Closure|null */
    private $colorRenderer;

    public function __construct(
        private CacheRepository $cache,
        ?Closure $fetcher = null,
        ?Closure $externalResolver = null,
        ?Closure $colorRenderer = null,
    ) {
        $this->fetcher = $fetcher;
        $this->externalResolver = $externalResolver;
        $this->colorRenderer = $colorRenderer;
    }

    public function dataUri(ResolvedImage $image, array $config): ?string
    {
        $cfg = $config['placeholder'] ?? [];
        if (empty($cfg['enabled'])) {
            return null;
        }

        if ($this->externalResolver !== null) {
            $uri = ($this->externalResolver)($image, $cfg);
            if (is_string($uri) && $uri !== '') {
                return $uri;
            }
        }

        $prefix = $config['cache']['prefix'] ?? 'respimg';
        $ttl    = $config['cache']['ttl'] ?? null;
        $key    = sprintf('%s:lqip:%s:%d', $prefix, $image->id, $image->mtime);

        $callback = fn () => $this->buildDataUri($image, $cfg);

        return $ttl === null
            ? $this->cache->rememberForever($key, $callback)
            : $this->cache->remember($key, $ttl, $callback);
    }

    private function buildDataUri(ResolvedImage $image, array $cfg): string
    {
        $payload = $this->fetcher
            ? ($this->fetcher)($image, $cfg)
            : $this->fetchViaGlide($image, $cfg);

        return sprintf(
            'data:%s;base64,%s',
            $payload['mime'] ?? 'image/jpeg',
            base64_encode($payload['bytes'] ?? '')
        );
    }

    public function color(ResolvedImage $image, array $config): ?string
    {
        $cfg = $config['placeholder']['color'] ?? [];
        if (empty($cfg['enabled'])) {
            return null;
        }

        $prefix = $config['cache']['prefix'] ?? 'respimg';
        $ttl    = $config['cache']['ttl'] ?? null;
        $key    = sprintf('%s:color:%s:%d', $prefix, $image->id, $image->mtime);

        $callback = fn () => $this->computeColor($image);

        return $ttl === null
            ? $this->cache->rememberForever($key, $callback)
            : $this->cache->remember($key, $ttl, $callback);
    }

    private function computeColor(ResolvedImage $image): ?string
    {
        $bytes = $this->colorRenderer
            ? ($this->colorRenderer)($image)
            : $this->renderViaGlide($image, ['w' => 1, 'h' => 1, 'fit' => 'crop', 'fm' => 'png']);

        if ($bytes === '' || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        // ponytail: a 1x1 downscale IS the average color; GD pixel read is enough
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }

        $rgb = imagecolorat($img, 0, 0);
        imagedestroy($img);

        return sprintf('#%02x%02x%02x', ($rgb >> 16) & 0xff, ($rgb >> 8) & 0xff, $rgb & 0xff);
    }

    private function fetchViaGlide(ResolvedImage $image, array $cfg): array
    {
        $bytes = $this->renderViaGlide($image, [
            'w'    => (int) ($cfg['width'] ?? 32),
            'blur' => (int) ($cfg['blur'] ?? 40),
            'q'    => (int) ($cfg['quality'] ?? 40),
            'fit'  => 'contain',
            'fm'   => 'jpg',
        ]);

        return ['bytes' => $bytes, 'mime' => 'image/jpeg'];
    }

    private function renderViaGlide(ResolvedImage $image, array $params): string
    {
        $generator = app(ImageGenerator::class);

        if ($image->isAsset()) {
            $path = $generator->generateByAsset($image->asset, $params);
        } elseif (preg_match('#^https?://#i', (string) $image->url)) {
            $path = $generator->generateByUrl($image->url, $params);
        } else {
            $path = $generator->generateByPath((string) $image->url, $params);
        }

        return (string) (app(GlideManager::class)->cacheDisk()->get($path) ?: '');
    }
}
