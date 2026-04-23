<?php

namespace Massif\ResponsiveImages\View;

use Massif\ResponsiveImages\Image\ResolvedImage;

class PassthroughRenderer
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function render(ResolvedImage $image, array $params): string
    {
        $src = $image->isAsset() && $image->asset !== null
            ? (string) $image->asset->url()
            : (string) $image->url;

        $alt      = (string) ($params['alt'] ?? '');
        $loading  = (string) ($params['loading'] ?? 'lazy');
        $decoding = (string) ($params['decoding'] ?? 'async');
        $class    = $params['class'] ?? null;
        $width    = (int) ($params['width'] ?? 0);
        $height   = (int) ($params['height'] ?? 0);

        $attrs = [
            'src'      => $src,
            'alt'      => $alt,
            'loading'  => $loading,
            'decoding' => $decoding,
        ];

        if ($width > 0) {
            $attrs['width'] = (string) $width;
        }
        if ($height > 0) {
            $attrs['height'] = (string) $height;
        }
        if (is_string($class) && $class !== '') {
            $attrs['class'] = $class;
        }
        $fetchpriority = $params['fetchpriority'] ?? null;
        if (is_string($fetchpriority) && $fetchpriority !== '') {
            $attrs['fetchpriority'] = $fetchpriority;
        }
        if ($alt === '') {
            $attrs['aria-hidden'] = 'true';
        }

        $rendered = '';
        foreach ($attrs as $k => $v) {
            $rendered .= ' '.$k.'="'.$this->e((string) $v).'"';
        }

        return '<img'.$rendered.'>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
