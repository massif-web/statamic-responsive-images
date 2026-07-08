<?php

namespace Massif\ResponsiveImages\Image;

use Closure;

/**
 * Decides which image formats to emit for a given image, and enforces AVIF's
 * minimum encodable dimension. Consolidates driver-support gating (issue #1),
 * the min-width threshold, and libavif's 16px floor in one testable unit.
 */
final class FormatPolicy
{
    /** @param Closure(string):bool $canEncode  memoized driver-encode probe */
    public function __construct(
        private array $config,
        private Closure $canEncode,
    ) {
    }

    /**
     * Formats to emit, priority order, always ending in 'fallback'.
     *
     * @param  list<string>|null  $requested  explicit `formats=` override, or null for config defaults
     * @return list<string>
     */
    public function formatsFor(?array $requested, int $maxWidth): array
    {
        $formats  = $this->config['formats'] ?? [];
        $minWidth = (int) ($formats['min_width'] ?? 0);
        $detect   = $formats['detect_support'] ?? true;

        $base = $requested ?? array_values(array_filter(
            ['avif', 'webp'],
            fn (string $f) => ! empty($formats[$f]['enabled'])
        ));

        $out = [];
        foreach ($base as $f) {
            if ($f === 'fallback') {
                continue; // re-appended below so it is always present, last, and unique
            }
            if ($f === 'avif' || $f === 'webp') {
                if ($minWidth > 0 && $maxWidth < $minWidth) {
                    continue; // tiny image: modern-format overhead not worth it
                }
                if ($detect && ! ($this->canEncode)($f)) {
                    continue; // driver cannot encode it; <picture> would not fall back
                }
            }
            $out[] = $f;
        }
        $out[] = 'fallback';

        return $out;
    }

    /** libavif refuses dimensions below 16px, silently producing 0-byte files. */
    public function avifWidthAllowed(int $w, ?int $h): bool
    {
        return $w >= 16 && ($h === null || $h >= 16);
    }
}
