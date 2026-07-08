<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use Massif\ResponsiveImages\Image\FormatPolicy;
use PHPUnit\Framework\TestCase;

class FormatPolicyTest extends TestCase
{
    private function config(array $formats = []): array
    {
        return ['formats' => array_replace([
            'avif'           => ['enabled' => true, 'quality' => 50],
            'webp'           => ['enabled' => true, 'quality' => 75],
            'fallback'       => ['quality' => 82],
            'detect_support' => true,
            'min_width'      => 0,
        ], $formats)];
    }

    public function test_returns_enabled_formats_plus_fallback(): void
    {
        $p = new FormatPolicy($this->config(), fn () => true);
        $this->assertSame(['avif', 'webp', 'fallback'], $p->formatsFor(null, 2000));
    }

    public function test_drops_format_the_driver_cannot_encode(): void
    {
        $p = new FormatPolicy($this->config(), fn (string $f) => $f !== 'avif');
        $this->assertSame(['webp', 'fallback'], $p->formatsFor(null, 2000));
    }

    public function test_detect_support_false_skips_probe(): void
    {
        $p = new FormatPolicy($this->config(['detect_support' => false]), fn () => false);
        $this->assertSame(['avif', 'webp', 'fallback'], $p->formatsFor(null, 2000));
    }

    public function test_config_disabled_format_excluded(): void
    {
        $p = new FormatPolicy(
            $this->config(['avif' => ['enabled' => false, 'quality' => 50]]),
            fn () => true
        );
        $this->assertSame(['webp', 'fallback'], $p->formatsFor(null, 2000));
    }

    public function test_min_width_threshold_drops_modern_formats(): void
    {
        $p = new FormatPolicy($this->config(['min_width' => 320]), fn () => true);
        $this->assertSame(['fallback'], $p->formatsFor(null, 300));
        $this->assertSame(['avif', 'webp', 'fallback'], $p->formatsFor(null, 320));
    }

    public function test_override_is_gated_and_fallback_always_last(): void
    {
        $p = new FormatPolicy($this->config(), fn (string $f) => $f !== 'avif');
        $this->assertSame(['webp', 'fallback'], $p->formatsFor(['avif', 'webp'], 2000));
    }

    public function test_override_containing_fallback_is_not_duplicated(): void
    {
        $p = new FormatPolicy($this->config(), fn () => true);
        $this->assertSame(['webp', 'fallback'], $p->formatsFor(['webp', 'fallback'], 2000));
    }

    public function test_avif_width_floor(): void
    {
        $p = new FormatPolicy($this->config(), fn () => true);
        $this->assertTrue($p->avifWidthAllowed(16, 16));
        $this->assertTrue($p->avifWidthAllowed(16, null));
        $this->assertFalse($p->avifWidthAllowed(15, 100));
        $this->assertFalse($p->avifWidthAllowed(100, 9));
    }
}
