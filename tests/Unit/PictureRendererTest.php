<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\View\PictureRenderer;

class PictureRendererTest extends TestCase
{
    private function baseData(): array
    {
        return [
            'sources' => [
                ['type' => 'image/avif', 'srcset' => 'a 400w', 'sizes' => '100vw', 'media' => null],
                ['type' => 'image/webp', 'srcset' => 'b 400w', 'sizes' => '100vw', 'media' => null],
            ],
            'img' => [
                'src' => '/i.jpg',
                'srcset' => 'c 400w',
                'sizes' => '100vw',
                'width' => 800,
                'height' => 450,
                'alt' => 'hello',
                'class' => null,
                'loading' => 'lazy',
                'decoding' => 'async',
                'fetchpriority' => 'auto',
                'placeholder' => null,
                'object_position' => null,
            ],
            'wrapper' => [
                'figure' => false,
                'ratio_wrapper' => false,
                'ratio' => null,
                'caption' => null,
                'class' => null,
            ],
        ];
    }

    public function test_basic_picture_output(): void
    {
        $html = (new PictureRenderer())->render($this->baseData());

        $this->assertStringContainsString('<picture>', $html);
        $this->assertStringContainsString('<source type="image/avif" srcset="a 400w" sizes="100vw">', $html);
        $this->assertStringContainsString('<source type="image/webp" srcset="b 400w" sizes="100vw">', $html);
        $this->assertStringContainsString('src="/i.jpg"', $html);
        $this->assertStringContainsString('width="800"', $html);
        $this->assertStringContainsString('height="450"', $html);
        $this->assertStringContainsString('alt="hello"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    public function test_escapes_user_strings(): void
    {
        $data = $this->baseData();
        $data['img']['alt'] = '"><script>x</script>';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;x&lt;/script&gt;', $html);
    }

    public function test_placeholder_style_inlined_on_img(): void
    {
        $data = $this->baseData();
        $data['img']['placeholder'] = 'data:image/jpeg;base64,AAA';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString(
            "style=\"background-size:cover;background-image:url('data:image/jpeg;base64,AAA')\"",
            $html
        );
    }

    public function test_ratio_wrapper(): void
    {
        $data = $this->baseData();
        $data['wrapper']['ratio_wrapper'] = true;
        $data['wrapper']['ratio'] = '16/9';
        $data['wrapper']['class'] = 'rounded';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString('<div style="aspect-ratio:16/9" class="rounded">', $html);
        $this->assertStringContainsString('</div>', $html);
    }

    public function test_figure_wrapper_with_caption(): void
    {
        $data = $this->baseData();
        $data['wrapper']['figure'] = true;
        $data['wrapper']['caption'] = 'A photo';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString('<figure>', $html);
        $this->assertStringContainsString('<figcaption>A photo</figcaption>', $html);
    }

    public function test_object_position_inlined_on_img(): void
    {
        $data = $this->baseData();
        $data['img']['object_position'] = '25% 75%';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString('object-position:25% 75%', $html);
    }

    public function test_object_position_combined_with_placeholder(): void
    {
        $data = $this->baseData();
        $data['img']['placeholder'] = 'data:image/jpeg;base64,AAA';
        $data['img']['object_position'] = '50% 10%';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString(
            "background-size:cover;background-image:url('data:image/jpeg;base64,AAA');--focal-point:50% 10%;object-position:50% 10%",
            $html
        );
    }

    public function test_art_direction_media_query(): void
    {
        $data = $this->baseData();
        $data['sources'] = [
            ['type' => 'image/webp', 'srcset' => 'm 400w', 'sizes' => '100vw', 'media' => '(max-width: 768px)'],
            ['type' => 'image/webp', 'srcset' => 'd 400w', 'sizes' => '100vw', 'media' => null],
        ];

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString('media="(max-width: 768px)"', $html);
    }

    public function test_focal_point_emits_css_variable_and_object_position(): void
    {
        $data = $this->baseData();
        $data['img']['object_position'] = '25% 75%';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString('--focal-point:25% 75%', $html);
        $this->assertStringContainsString('object-position:25% 75%', $html);
    }

    public function test_aria_hidden_when_alt_empty(): void
    {
        $data = $this->baseData();
        $data['img']['alt'] = '';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringContainsString('alt=""', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_no_aria_hidden_when_alt_present(): void
    {
        $data = $this->baseData();
        $data['img']['alt'] = 'described';

        $html = (new PictureRenderer())->render($data);

        $this->assertStringNotContainsString('aria-hidden', $html);
    }
}
