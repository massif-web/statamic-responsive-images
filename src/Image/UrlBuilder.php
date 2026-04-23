<?php

namespace Massif\ResponsiveImages\Image;

use Closure;
use Statamic\Facades\Image as Glide;

class UrlBuilder
{
    private const EXTRAS_INT    = ['blur', 'brightness', 'contrast', 'pixelate', 'sharpen'];
    private const EXTRAS_FLOAT  = ['gamma'];
    private const EXTRAS_STRING = ['bg', 'filter', 'flip', 'orient'];

    /** @var Closure|null */
    private $urlFactory;

    public function __construct(?Closure $urlFactory = null)
    {
        $this->urlFactory = $urlFactory;
    }

    /**
     * @param  array<string, mixed>  $extras
     */
    public function build(
        ResolvedImage $image,
        int $width,
        string $format,
        int $quality,
        ?int $height = null,
        ?string $fit = null,
        array $extras = [],
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

        foreach ($this->filterExtras($extras) as $key => $value) {
            if (array_key_exists($key, $params)) {
                continue;
            }
            $params[$key] = $value;
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

    /**
     * @param  array<string, mixed>  $extras
     * @return array<string, int|float|string>
     */
    private function filterExtras(array $extras): array
    {
        $out = [];
        foreach ($extras as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (in_array($key, self::EXTRAS_INT, true)) {
                if (is_numeric($value)) {
                    $out[$key] = (int) $value;
                }
                continue;
            }
            if (in_array($key, self::EXTRAS_FLOAT, true)) {
                if (is_numeric($value)) {
                    $out[$key] = (float) $value;
                }
                continue;
            }
            if (in_array($key, self::EXTRAS_STRING, true)) {
                $trimmed = trim((string) $value);
                if ($trimmed !== '') {
                    $out[$key] = $trimmed;
                }
            }
        }
        return $out;
    }
}
