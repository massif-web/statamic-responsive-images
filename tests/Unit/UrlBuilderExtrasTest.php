<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\Image\UrlBuilder;
use Massif\ResponsiveImages\Image\ResolvedImage;

class UrlBuilderExtrasTest extends TestCase
{
    private function image(): ResolvedImage
    {
        return new ResolvedImage(asset: null, id: 'x', mtime: 0, url: '/o.jpg');
    }

    private function stub(): UrlBuilder
    {
        return new UrlBuilder(
            urlFactory: function (ResolvedImage $image, array $params) {
                ksort($params);
                return '/img/'.$image->id.'?'.http_build_query($params);
            }
        );
    }

    public function test_whitelisted_extras_pass_through(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'webp', quality: 75,
            extras: ['blur' => 10, 'sharpen' => 5]);

        $this->assertStringContainsString('blur=10', $url);
        $this->assertStringContainsString('sharpen=5', $url);
    }

    public function test_non_whitelisted_extras_are_dropped(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'webp', quality: 75,
            extras: ['dpr' => 2, 'bogus' => 'evil']);

        $this->assertStringNotContainsString('dpr=', $url);
        $this->assertStringNotContainsString('bogus=', $url);
    }

    public function test_non_numeric_values_dropped_for_numeric_keys(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'webp', quality: 75,
            extras: ['blur' => 'abc', 'sharpen' => '7']);

        $this->assertStringNotContainsString('blur=', $url);
        $this->assertStringContainsString('sharpen=7', $url);
    }

    public function test_extras_cannot_override_core_keys(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'webp', quality: 75,
            extras: ['w' => 9999, 'h' => 9999, 'q' => 1, 'fm' => 'jpg', 'fit' => 'stretch']);

        $this->assertStringContainsString('w=800', $url);
        $this->assertStringNotContainsString('w=9999', $url);
        $this->assertStringContainsString('q=75', $url);
        $this->assertStringNotContainsString('q=1', $url);
        $this->assertStringContainsString('fm=webp', $url);
    }

    public function test_string_passthrough_keys_trim(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'webp', quality: 75,
            extras: ['flip' => '  h  ', 'filter' => 'sepia']);

        $this->assertStringContainsString('flip=h', $url);
        $this->assertStringContainsString('filter=sepia', $url);
    }

    public function test_gamma_preserves_float(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'webp', quality: 75,
            extras: ['gamma' => 1.5]);

        $this->assertStringContainsString('gamma=1.5', $url);
    }
}
