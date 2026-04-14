<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\Image\UrlBuilder;
use Massif\ResponsiveImages\Image\ResolvedImage;

class UrlBuilderTest extends TestCase
{
    private function image(): ResolvedImage
    {
        return new ResolvedImage(asset: null, id: 'fake-id', mtime: 1234, url: '/original.jpg');
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

    public function test_builds_webp_url_with_width_and_quality(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'webp', quality: 75);

        $this->assertSame('/img/fake-id?fm=webp&q=75&w=800', $url);
    }

    public function test_includes_height_and_fit_when_ratio_active(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 800, format: 'avif', quality: 50, height: 450, fit: 'crop_focal');

        $this->assertSame('/img/fake-id?fit=crop_focal&fm=avif&h=450&q=50&w=800', $url);
    }

    public function test_fallback_format_omits_fm(): void
    {
        $url = $this->stub()->build($this->image(),
            width: 828, format: 'fallback', quality: 82);

        $this->assertSame('/img/fake-id?q=82&w=828', $url);
    }
}
