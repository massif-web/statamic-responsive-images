<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\View\PassthroughRenderer;
use Massif\ResponsiveImages\Image\ResolvedImage;

class PassthroughRendererTest extends TestCase
{
    private function image(string $url = '/file.svg'): ResolvedImage
    {
        return new ResolvedImage(asset: null, id: 'id', mtime: 0, url: $url);
    }

    public function test_renders_bare_img_with_core_attrs(): void
    {
        $html = (new PassthroughRenderer())->render($this->image('/u/a.svg'), [
            'alt' => 'a logo',
        ]);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('src="/u/a.svg"', $html);
        $this->assertStringContainsString('alt="a logo"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('decoding="async"', $html);
        $this->assertStringNotContainsString('<picture', $html);
        $this->assertStringNotContainsString('srcset=', $html);
        $this->assertStringNotContainsString('sizes=', $html);
    }

    public function test_aria_hidden_when_alt_empty(): void
    {
        $html = (new PassthroughRenderer())->render($this->image(), [
            'alt' => '',
        ]);

        $this->assertStringContainsString('alt=""', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_no_aria_hidden_when_alt_present(): void
    {
        $html = (new PassthroughRenderer())->render($this->image(), [
            'alt' => 'x',
        ]);

        $this->assertStringNotContainsString('aria-hidden', $html);
    }

    public function test_includes_intrinsic_dimensions_when_provided(): void
    {
        $html = (new PassthroughRenderer())->render($this->image(), [
            'alt'    => 'x',
            'width'  => 320,
            'height' => 240,
        ]);

        $this->assertStringContainsString('width="320"', $html);
        $this->assertStringContainsString('height="240"', $html);
    }

    public function test_omits_dimensions_when_zero(): void
    {
        $html = (new PassthroughRenderer())->render($this->image(), [
            'alt'    => 'x',
            'width'  => 0,
            'height' => 0,
        ]);

        $this->assertStringNotContainsString('width=', $html);
        $this->assertStringNotContainsString('height=', $html);
    }

    public function test_honors_class_loading_decoding(): void
    {
        $html = (new PassthroughRenderer())->render($this->image(), [
            'alt'      => 'x',
            'class'    => 'hero',
            'loading'  => 'eager',
            'decoding' => 'sync',
        ]);

        $this->assertStringContainsString('class="hero"', $html);
        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringContainsString('decoding="sync"', $html);
    }

    public function test_escapes_html_in_attrs(): void
    {
        $html = (new PassthroughRenderer())->render($this->image('/u/x.svg'), [
            'alt' => '"><script>y</script>',
        ]);

        $this->assertStringNotContainsString('<script>y</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_forwards_fetchpriority(): void
    {
        $html = (new PassthroughRenderer())->render($this->image(), [
            'alt'           => 'x',
            'fetchpriority' => 'high',
        ]);

        $this->assertStringContainsString('fetchpriority="high"', $html);
    }

    public function test_omits_fetchpriority_when_empty(): void
    {
        $html = (new PassthroughRenderer())->render($this->image(), [
            'alt'           => 'x',
            'fetchpriority' => '',
        ]);

        $this->assertStringNotContainsString('fetchpriority', $html);
    }
}
