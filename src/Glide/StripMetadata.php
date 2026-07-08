<?php

namespace Massif\ResponsiveImages\Glide;

use Imagick;
use ImagickException;
use Intervention\Image\Interfaces\ImageInterface;
use League\Glide\Manipulators\ManipulatorInterface;

/**
 * Strips EXIF/XMP/IPTC/ICC metadata on encode. Opt-in via config `strip_metadata`.
 * Runs AFTER ColorProfile so the sRGB conversion is preserved (browsers assume
 * sRGB by default, so the dropped ICC profile is not needed). GD carries no
 * metadata through a re-encode, so this is an Imagick-only no-op elsewhere.
 */
class StripMetadata implements ManipulatorInterface
{
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

        if (! $native instanceof Imagick) {
            return $image;
        }

        try {
            $native->stripImage();
        } catch (ImagickException) {
            // Deliver unstripped rather than fail the render.
        }

        return $image;
    }
}
