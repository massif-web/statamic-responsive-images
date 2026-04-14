<?php

namespace Massif\ResponsiveImages\Image;

use Statamic\Contracts\Assets\Asset;

final class ResolvedImage
{
    public function __construct(
        public readonly ?Asset $asset,
        public readonly string $id,
        public readonly int $mtime,
        public readonly ?string $url = null,
    ) {
    }

    public function isAsset(): bool
    {
        return $this->asset !== null;
    }
}
