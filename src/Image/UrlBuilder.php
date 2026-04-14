<?php

namespace Massif\ResponsiveImages\Image;

use Closure;
use Statamic\Facades\Image as Glide;

class UrlBuilder
{
    /** @var Closure|null */
    private $urlFactory;

    public function __construct(?Closure $urlFactory = null)
    {
        $this->urlFactory = $urlFactory;
    }

    public function build(
        ResolvedImage $image,
        int $width,
        string $format,
        int $quality,
        ?int $height = null,
        ?string $fit = null,
    ): string {
        $params = ['w' => $width, 'q' => $quality];

        if ($height !== null) {
            $params['h'] = $height;
        }

        if ($fit !== null) {
            $params['fit'] = $fit;
        }

        if ($format !== 'fallback') {
            $params['fm'] = $format;
        }

        if ($this->urlFactory) {
            return ($this->urlFactory)($image, $params);
        }

        $manipulator = $image->isAsset()
            ? Glide::manipulate($image->asset)
            : Glide::manipulate($image->url);

        foreach ($params as $key => $value) {
            $manipulator->$key($value);
        }

        return $manipulator->build();
    }
}
