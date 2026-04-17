<?php

namespace Massif\ResponsiveImages\Image;

use Illuminate\Support\Facades\Log;

class MetadataReader
{
    public function read(ResolvedImage $image): ImageMetadata
    {
        try {
            if ($image->isAsset() && $image->asset !== null) {
                return new ImageMetadata(
                    (int) $image->asset->width(),
                    (int) $image->asset->height(),
                    (string) $image->asset->mimeType(),
                );
            }

            $info = @getimagesize((string) $image->url);
            if ($info === false) {
                return ImageMetadata::failed();
            }

            return new ImageMetadata(
                (int) $info[0],
                (int) $info[1],
                (string) $info['mime'],
            );
        } catch (\Exception $e) {
            Log::warning('[responsive_image] metadata read failed', [
                'id'    => $image->id,
                'error' => $e->getMessage(),
            ]);
            return ImageMetadata::failed();
        }
    }
}
