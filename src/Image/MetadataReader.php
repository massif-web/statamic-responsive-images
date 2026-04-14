<?php

namespace Massif\ResponsiveImages\Image;

class MetadataReader
{
    /**
     * @return array{width: int, height: int, mime: string}
     */
    public function read(ResolvedImage $image): array
    {
        if ($image->isAsset() && $image->asset !== null) {
            return [
                'width'  => (int) $image->asset->width(),
                'height' => (int) $image->asset->height(),
                'mime'   => (string) $image->asset->mimeType(),
            ];
        }

        $info = @getimagesize((string) $image->url);
        if ($info === false) {
            return ['width' => 0, 'height' => 0, 'mime' => 'application/octet-stream'];
        }

        return [
            'width'  => (int) $info[0],
            'height' => (int) $info[1],
            'mime'   => (string) $info['mime'],
        ];
    }
}
