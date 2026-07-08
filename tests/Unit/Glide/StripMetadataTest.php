<?php

namespace Massif\ResponsiveImages\Tests\Unit\Glide;

use Intervention\Image\Interfaces\CoreInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Massif\ResponsiveImages\Glide\StripMetadata;
use PHPUnit\Framework\TestCase;

class StripMetadataTest extends TestCase
{
    private function imageWithNative(mixed $native): ImageInterface
    {
        $core = $this->createMock(CoreInterface::class);
        $core->method('native')->willReturn($native);

        $image = $this->createMock(ImageInterface::class);
        $image->method('core')->willReturn($core);

        return $image;
    }

    public function test_noop_when_native_is_not_imagick(): void
    {
        $image = $this->imageWithNative(new \stdClass);

        $result = (new StripMetadata())->run($image);

        $this->assertSame($image, $result);
    }

    public function test_strips_on_imagick(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded');
        }

        $imagick = $this->createMock(\Imagick::class);
        $imagick->expects($this->once())->method('stripImage');

        $image = $this->imageWithNative($imagick);

        $result = (new StripMetadata())->run($image);

        $this->assertSame($image, $result);
    }

    public function test_swallows_imagick_exception(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded');
        }

        $imagick = $this->createMock(\Imagick::class);
        $imagick->method('stripImage')
            ->willThrowException(new \ImagickException('boom'));

        $image = $this->imageWithNative($imagick);

        $result = (new StripMetadata())->run($image);

        $this->assertSame($image, $result);
    }

    public function test_api_params_empty(): void
    {
        $this->assertSame([], (new StripMetadata())->getApiParams());
    }
}
