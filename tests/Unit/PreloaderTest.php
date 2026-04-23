<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\View\Preloader;

class PreloaderTest extends TestCase
{
    private array $captured = [];

    private function preloader(): Preloader
    {
        $this->captured = [];
        return new Preloader(pusher: function (string $stackName, string $content) {
            $this->captured[] = ['stack' => $stackName, 'content' => $content];
        });
    }

    public function test_push_emits_link_with_srcset_sizes_type(): void
    {
        $this->preloader()->push(
            srcset: '/img/a?w=400 400w, /img/a?w=800 800w',
            sizes: '100vw',
            mimeType: 'image/avif',
        );

        $this->assertCount(1, $this->captured);
        $this->assertSame('head', $this->captured[0]['stack']);

        $link = $this->captured[0]['content'];
        $this->assertStringContainsString('<link', $link);
        $this->assertStringContainsString('rel="preload"', $link);
        $this->assertStringContainsString('as="image"', $link);
        $this->assertStringContainsString('imagesrcset="/img/a?w=400 400w, /img/a?w=800 800w"', $link);
        $this->assertStringContainsString('imagesizes="100vw"', $link);
        $this->assertStringContainsString('type="image/avif"', $link);
        $this->assertStringContainsString('fetchpriority="high"', $link);
    }

    public function test_push_escapes_srcset_and_sizes(): void
    {
        $this->preloader()->push(
            srcset: '"><script>x</script>',
            sizes: '100vw',
            mimeType: 'image/webp',
        );

        $link = $this->captured[0]['content'];
        $this->assertStringNotContainsString('<script>x</script>', $link);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $link);
    }

    public function test_push_noops_when_srcset_empty(): void
    {
        $this->preloader()->push(srcset: '', sizes: '100vw', mimeType: 'image/avif');

        $this->assertSame([], $this->captured);
    }

    public function test_push_noops_when_mime_empty(): void
    {
        $this->preloader()->push(srcset: '/a 1w', sizes: '100vw', mimeType: '');

        $this->assertSame([], $this->captured);
    }
}
