<?php

namespace Massif\ResponsiveImages\Image;

use Closure;
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

        if ($src instanceof Asset) {
            return $this->fromAsset($src);
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
