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

    public function __construct(
        private CacheRepository $cache,
        ?Closure $fetcher = null,
    ) {
        $this->fetcher = $fetcher;
    }

    public function dataUri(ResolvedImage $image, array $config): ?string
    {
        $cfg = $config['placeholder'] ?? [];
        if (empty($cfg['enabled'])) {
            return null;
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

    private function fetchViaGlide(ResolvedImage $image, array $cfg): array
    {
        $params = [
            'w'    => (int) ($cfg['width'] ?? 32),
            'blur' => (int) ($cfg['blur'] ?? 40),
            'q'    => (int) ($cfg['quality'] ?? 40),
            'fm'   => 'jpg',
        ];

        $generator = app(ImageGenerator::class);
        $path = $image->isAsset()
            ? $generator->generateByAsset($image->asset, $params)
            : $generator->generateByUrl($image->url, $params);

        $bytes = app(GlideManager::class)->cacheDisk()->get($path) ?: '';

        return ['bytes' => (string) $bytes, 'mime' => 'image/jpeg'];
    }
}
