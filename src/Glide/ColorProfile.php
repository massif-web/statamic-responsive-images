<?php

namespace Massif\ResponsiveImages\Glide;

use Imagick;
use ImagickException;
use Intervention\Image\Interfaces\ImageInterface;
use League\Glide\Manipulators\ManipulatorInterface;

class ColorProfile implements ManipulatorInterface
{
    public function __construct(private string $profilePath)
    {
    }

    public function setParams(array $params): static
    {
        return $this;
    }

    public function getParam(string $name): mixed
    {
        return null;
    }

    public function getApiParams(): array
    {
        return [];
    }

    public function run(ImageInterface $image): ImageInterface
    {
        $native = $image->core()->native();

        // TODO: vips branch via icc_transform
        if (! $native instanceof Imagick) {
            return $image;
        }

        $sRgb = @file_get_contents($this->profilePath);
        if ($sRgb === false) {
            return $image;
        }

        // profileImage triggers an lcms-based conversion from the source colorspace
        // to sRGB. transformImageColorspace must run AFTER — reversing these produces
        // washed-out output, which is the bug this manipulator exists to fix.
        //
        // If ImageMagick was built without the lcms delegate, profileImage throws
        // ImagickException. We swallow it and no-op so delivery proceeds with the
        // unconverted image — matching pre-addon behavior rather than breaking it.
        try {
            $native->profileImage('icc', $sRgb);
            $native->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        } catch (ImagickException) {
            return $image;
        }

        return $image;
    }
}
