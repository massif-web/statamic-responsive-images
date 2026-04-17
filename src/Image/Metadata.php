<?php

namespace Massif\ResponsiveImages\Image;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

class Metadata
{
    public function __construct(
        private MetadataReader $reader,
        private CacheRepository $cache,
        private string $prefix = 'respimg',
        private int $metadataTtl = 7_776_000,
        private int $sentinelTtl = 60,
    ) {
    }

    public function for(ResolvedImage $image): ImageMetadata
    {
        $key = sprintf('%s:meta:%s:%d', $this->prefix, $image->id, $image->mtime);

        // Cache as a plain array, not the ImageMetadata DTO: Laravel 12's
        // cache.serializable_classes can reject unknown classes during
        // unserialize, returning __PHP_Incomplete_Class and breaking the
        // round-trip. Arrays of scalars are unaffected.
        $cached = $this->cache->get($key);
        if (is_array($cached) && array_key_exists('width', $cached)) {
            return ImageMetadata::fromArray($cached);
        }

        $meta = $this->reader->read($image);

        $ttl = $meta->failed ? $this->sentinelTtl : $this->metadataTtl;
        $this->cache->put($key, $meta->toArray(), $ttl);

        return $meta;
    }
}
