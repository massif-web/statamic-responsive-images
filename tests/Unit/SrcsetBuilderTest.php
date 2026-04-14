<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\Image\SrcsetBuilder;

class SrcsetBuilderTest extends TestCase
{
    private function config(): array
    {
        return [
            'device_sizes' => [640, 750, 828, 1080, 1200, 1920, 2048, 3840],
            'image_sizes'  => [16, 32, 48, 64, 96, 128, 256, 384],
        ];
    }

    public function test_combines_and_sorts_pools_capped_at_source_width(): void
    {
        $builder = new SrcsetBuilder();

        $widths = $builder->build(sourceWidth: 1500, config: $this->config());

        $this->assertSame(
            [16, 32, 48, 64, 96, 128, 256, 384, 640, 750, 828, 1080, 1200],
            $widths
        );
    }

    public function test_never_upscales_past_source_width(): void
    {
        $builder = new SrcsetBuilder();

        $widths = $builder->build(sourceWidth: 500, config: $this->config());

        foreach ($widths as $w) {
            $this->assertLessThanOrEqual(500, $w);
        }
    }

    public function test_override_widths_still_capped(): void
    {
        $builder = new SrcsetBuilder();

        $widths = $builder->build(
            sourceWidth: 1000,
            config: $this->config(),
            override: [400, 800, 1600, 3200]
        );

        $this->assertSame([400, 800, 1000], $widths);
    }

    public function test_override_widths_deduped_and_sorted(): void
    {
        $builder = new SrcsetBuilder();

        $widths = $builder->build(
            sourceWidth: 5000,
            config: $this->config(),
            override: [800, 400, 800, 1200]
        );

        $this->assertSame([400, 800, 1200], $widths);
    }

    public function test_empty_result_when_source_smaller_than_smallest(): void
    {
        $builder = new SrcsetBuilder();

        $widths = $builder->build(sourceWidth: 8, config: $this->config());

        $this->assertSame([8], $widths);
    }
}
