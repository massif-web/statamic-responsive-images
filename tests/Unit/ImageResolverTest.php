<?php

namespace Massif\ResponsiveImages\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Massif\ResponsiveImages\Image\ImageResolver;

class ImageResolverTest extends TestCase
{
    public function test_resolves_url_string(): void
    {
        $resolver = new ImageResolver(
            assetLookup: fn () => null,
        );

        $resolved = $resolver->resolve('/uploads/photo.jpg');

        $this->assertNotNull($resolved);
        $this->assertFalse($resolved->isAsset());
        $this->assertSame('/uploads/photo.jpg', $resolved->url);
        $this->assertSame(md5('/uploads/photo.jpg'), $resolved->id);
    }

    public function test_returns_null_for_empty(): void
    {
        $resolver = new ImageResolver(assetLookup: fn () => null);

        $this->assertNull($resolver->resolve(''));
        $this->assertNull($resolver->resolve(null));
    }

    public function test_resolves_asset_reference(): void
    {
        $fakeAsset = new class {
            public function id() { return 'main::photo.jpg'; }
            public function lastModified() { return new \DateTime('@1700000000'); }
            public function url() { return '/assets/main/photo.jpg'; }
        };

        $resolver = new ImageResolver(
            assetLookup: fn (string $ref) => $ref === 'assets::main::photo.jpg' ? $fakeAsset : null,
        );

        $resolved = $resolver->resolve('assets::main::photo.jpg');

        $this->assertNotNull($resolved);
        $this->assertSame('main::photo.jpg', $resolved->id);
        $this->assertSame(1700000000, $resolved->mtime);
    }
}
