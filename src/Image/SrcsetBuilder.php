<?php

namespace Massif\ResponsiveImages\Image;

class SrcsetBuilder
{
    /**
     * @param  array<string, mixed>  $config
     * @param  int[]|null  $override
     * @return int[]
     */
    public function build(int $sourceWidth, array $config, ?array $override = null): array
    {
        $usingOverride = $override !== null;

        $pool = $override ?? array_merge(
            $config['image_sizes'] ?? [],
            $config['device_sizes'] ?? []
        );

        $pool = array_values(array_unique(array_map('intval', $pool)));
        sort($pool);

        $max = $pool === [] ? 0 : max($pool);
        $widths = array_values(array_filter($pool, fn (int $w) => $w <= $sourceWidth));

        if ($widths === []) {
            return [$sourceWidth];
        }

        if ($usingOverride && $max > $sourceWidth) {
            $widths[] = $sourceWidth;
            $widths = array_values(array_unique($widths));
            sort($widths);
        }

        return $widths;
    }
}
