<?php

namespace Massif\ResponsiveImages\Image;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

class Metadata
{
    public function __construct(
        private MetadataReader $reader,
        private CacheRepository $cache,
        private string $prefix = 'respimg',
        private ?int $ttl = null,
    ) {
    }

    /**
     * @return array{width: int, height: int, mime: string}
     */
    public function for(ResolvedImage $image): array
    {
        $key = sprintf('%s:meta:%s:%d', $this->prefix, $image->id, $image->mtime);

        $callback = fn () => $this->reader->read($image);

        return $this->ttl === null
            ? $this->cache->rememberForever($key, $callback)
            : $this->cache->remember($key, $this->ttl, $callback);
    }
}
