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

            $info = @getimagesize($this->resolveToFilesystemPath((string) $image->url));
            if ($info === false) {
                $this->logFailure($image->id, 'unreadable');
                return ImageMetadata::failed();
            }

            return new ImageMetadata(
                (int) $info[0],
                (int) $info[1],
                (string) $info['mime'],
            );
        } catch (\Exception $e) {
            $this->logFailure($image->id, 'exception', $e->getMessage());
            return ImageMetadata::failed();
        }
    }

    private function logFailure(string $id, string $reason, ?string $error = null): void
    {
        $context = ['id' => $id, 'reason' => $reason];
        if ($error !== null) {
            $context['error'] = $error;
        }

        try {
            Log::warning('[responsive_image] metadata read failed', $context);
        } catch (\RuntimeException) {
            // No Laravel application container in this context; skip logging.
        }
    }

    private function resolveToFilesystemPath(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) === 1) {
            return $url;
        }

        if ($url[0] === '/' && function_exists('public_path')) {
            $candidate = public_path(ltrim($url, '/'));
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $url;
    }
}
