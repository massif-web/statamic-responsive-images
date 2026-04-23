<?php

namespace Massif\ResponsiveImages\Image;

use Closure;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Asset as AssetFacade;

class ImageResolver
{
    /** @var Closure|null */
    private $assetLookup;

    public function __construct(?Closure $assetLookup = null)
    {
        $this->assetLookup = $assetLookup;
    }

    public function resolve(mixed $src): ?ResolvedImage
    {
        if ($src === null || $src === '') {
            return null;
        }

        if (is_array($src)) {
            if ($src === []) {
                return null;
            }
            if (count($src) > 1) {
                try {
                    Log::debug('[responsive_image] array src has multiple elements; using first', [
                        'count' => count($src),
                    ]);
                } catch (\RuntimeException) {
                    // No Laravel application container in this context; skip logging.
                }
            }
            return $this->resolve($src[0]);
        }

        if ($src instanceof Asset) {
            return $this->fromAsset($src);
        }

        if ($src instanceof \Traversable) {
            return $this->resolve(iterator_to_array($src, false));
        }

        if ($src instanceof \Illuminate\Contracts\Support\Arrayable) {
            return $this->resolve($src->toArray());
        }

        if (is_object($src) && method_exists($src, 'id') && method_exists($src, 'url')) {
            return $this->fromAsset($src);
        }

        if (is_string($src) && str_starts_with($src, 'assets::')) {
            $asset = $this->lookupAsset($src);
            if ($asset !== null) {
                return $this->fromAsset($asset);
            }
            return null;
        }

        if (is_string($src)) {
            return new ResolvedImage(
                asset: null,
                id:    md5($src),
                mtime: 0,
                url:   $src,
            );
        }

        return null;
    }

    private function fromAsset(object $asset): ResolvedImage
    {
        $mtime = 0;
        if (method_exists($asset, 'lastModified')) {
            $last = $asset->lastModified();
            $mtime = $last instanceof \DateTimeInterface ? $last->getTimestamp() : (int) $last;
        }

        return new ResolvedImage(
            asset: $asset instanceof Asset ? $asset : null,
            id:    (string) $asset->id(),
            mtime: $mtime,
            url:   (string) $asset->url(),
        );
    }

    private function lookupAsset(string $ref): ?object
    {
        if ($this->assetLookup) {
            return ($this->assetLookup)($ref);
        }

        return AssetFacade::find(substr($ref, strlen('assets::')));
    }
}
