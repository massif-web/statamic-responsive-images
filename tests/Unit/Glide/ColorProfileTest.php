<?php

namespace Massif\ResponsiveImages\Tests\Unit\Glide;

use Intervention\Image\Interfaces\CoreInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Massif\ResponsiveImages\Glide\ColorProfile;
use PHPUnit\Framework\TestCase;

class ColorProfileTest extends TestCase
{
    private string $profilePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profilePath = tempnam(sys_get_temp_dir(), 'icc');
        file_put_contents($this->profilePath, 'FAKE_ICC_BYTES');
    }

    protected function tearDown(): void
    {
        @unlink($this->profilePath);
        parent::tearDown();
    }

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

        $result = (new ColorProfile($this->profilePath))->run($image);

        $this->assertSame($image, $result);
    }

    public function test_noop_when_profile_file_is_unreadable(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded');
        }

        $imagick = $this->createMock(\Imagick::class);
        $imagick->expects($this->never())->method('profileImage');
        $imagick->expects($this->never())->method('transformImageColorspace');

        $image = $this->imageWithNative($imagick);

        $result = (new ColorProfile('/nonexistent/path/to/profile.icc'))->run($image);

        $this->assertSame($image, $result);
    }

    public function test_applies_profile_then_sets_colorspace_in_order(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded');
        }

        $calls = [];

        $imagick = $this->createMock(\Imagick::class);
        $imagick->method('profileImage')->willReturnCallback(
            function (string $name, ?string $profile) use (&$calls) {
                $calls[] = ['profileImage', $name, $profile];
                return true;
            }
        );
        $imagick->method('transformImageColorspace')->willReturnCallback(
            function (int $cs) use (&$calls) {
                $calls[] = ['transformImageColorspace', $cs];
                return true;
            }
        );

        $image = $this->imageWithNative($imagick);

        $result = (new ColorProfile($this->profilePath))->run($image);

        $this->assertSame($image, $result);
        $this->assertSame([
            ['profileImage', 'icc', 'FAKE_ICC_BYTES'],
            ['transformImageColorspace', \Imagick::COLORSPACE_SRGB],
        ], $calls);
    }

    public function test_swallows_imagick_exception_when_lcms_missing(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded');
        }

        $imagick = $this->createMock(\Imagick::class);
        $imagick->method('profileImage')
            ->willThrowException(new \ImagickException('no lcms delegate'));
        $imagick->expects($this->never())->method('transformImageColorspace');

        $image = $this->imageWithNative($imagick);

        $result = (new ColorProfile($this->profilePath))->run($image);

        $this->assertSame($image, $result);
    }
}
