<?php

namespace Massif\ResponsiveImages\Image;

final class ImageMetadata
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $mime,
        public readonly bool $failed = false,
    ) {
    }

    public static function failed(): self
    {
        return new self(0, 0, 'application/octet-stream', failed: true);
    }

    /**
     * @return array{width: int, height: int, mime: string, failed: bool}
     */
    public function toArray(): array
    {
        return [
            'width'  => $this->width,
            'height' => $this->height,
            'mime'   => $this->mime,
            'failed' => $this->failed,
        ];
    }

    /**
     * @param  array{width?: int|string, height?: int|string, mime?: string, failed?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['width'] ?? 0),
            (int) ($data['height'] ?? 0),
            (string) ($data['mime'] ?? 'application/octet-stream'),
            (bool) ($data['failed'] ?? false),
        );
    }
}
