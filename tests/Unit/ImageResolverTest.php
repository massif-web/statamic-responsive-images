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

    public function test_unwraps_array_with_single_asset(): void
    {
        $fakeAsset = new class {
            public function id() { return 'main::photo.jpg'; }
            public function lastModified() { return new \DateTime('@1700000000'); }
            public function url() { return '/assets/main/photo.jpg'; }
        };

        $resolver = new ImageResolver(assetLookup: fn () => null);

        $resolved = $resolver->resolve([$fakeAsset]);

        $this->assertNotNull($resolved);
        $this->assertSame('main::photo.jpg', $resolved->id);
    }

    public function test_unwraps_array_with_url_string(): void
    {
        $resolver = new ImageResolver(assetLookup: fn () => null);

        $resolved = $resolver->resolve(['/uploads/photo.jpg']);

        $this->assertNotNull($resolved);
        $this->assertFalse($resolved->isAsset());
        $this->assertSame('/uploads/photo.jpg', $resolved->url);
    }

    public function test_empty_array_returns_null(): void
    {
        $resolver = new ImageResolver(assetLookup: fn () => null);

        $this->assertNull($resolver->resolve([]));
    }

    public function test_array_with_null_returns_null(): void
    {
        $resolver = new ImageResolver(assetLookup: fn () => null);

        $this->assertNull($resolver->resolve([null]));
    }

    public function test_multi_element_array_resolves_first(): void
    {
        $first = new class {
            public function id() { return 'main::first.jpg'; }
            public function lastModified() { return new \DateTime('@1700000000'); }
            public function url() { return '/assets/main/first.jpg'; }
        };
        $second = new class {
            public function id() { return 'main::second.jpg'; }
            public function lastModified() { return new \DateTime('@1700000001'); }
            public function url() { return '/assets/main/second.jpg'; }
        };

        $resolver = new ImageResolver(assetLookup: fn () => null);

        $resolved = $resolver->resolve([$first, $second]);

        $this->assertNotNull($resolved);
        $this->assertSame('main::first.jpg', $resolved->id);
    }

    public function test_resolves_traversable_to_first_asset(): void
    {
        $first = new class {
            public function id() { return 'main::first.jpg'; }
            public function lastModified() { return new \DateTime('@1700000000'); }
            public function url() { return '/assets/main/first.jpg'; }
        };
        $second = new class {
            public function id() { return 'main::second.jpg'; }
            public function lastModified() { return new \DateTime('@1700000001'); }
            public function url() { return '/assets/main/second.jpg'; }
        };

        $iterator = new \ArrayIterator([$first, $second]);

        $resolver = new ImageResolver(assetLookup: fn () => null);

        $resolved = $resolver->resolve($iterator);

        $this->assertNotNull($resolved);
        $this->assertSame('main::first.jpg', $resolved->id);
    }

    public function test_resolves_arrayable_to_first_asset(): void
    {
        $asset = new class {
            public function id() { return 'main::only.jpg'; }
            public function lastModified() { return new \DateTime('@1700000000'); }
            public function url() { return '/assets/main/only.jpg'; }
        };

        $arrayable = new class($asset) implements \Illuminate\Contracts\Support\Arrayable {
            public function __construct(private object $asset) {}
            public function toArray(): array { return [$this->asset]; }
        };

        $resolver = new ImageResolver(assetLookup: fn () => null);

        $resolved = $resolver->resolve($arrayable);

        $this->assertNotNull($resolved);
        $this->assertSame('main::only.jpg', $resolved->id);
    }

    public function test_empty_arrayable_returns_null(): void
    {
        $empty = new class implements \Illuminate\Contracts\Support\Arrayable {
            public function toArray(): array { return []; }
        };

        $resolver = new ImageResolver(assetLookup: fn () => null);

        $this->assertNull($resolver->resolve($empty));
    }

    public function test_traversable_wins_over_arrayable_when_both_implemented(): void
    {
        // If an object is both Traversable and Arrayable with different contents,
        // the Traversable branch should fire first. This guards against future
        // reorderings that could silently change behavior for Collection-like types.
        $iteratedAsset = new class {
            public function id() { return 'main::from-iterator.jpg'; }
            public function lastModified() { return new \DateTime('@1700000000'); }
            public function url() { return '/assets/main/from-iterator.jpg'; }
        };
        $arrayableAsset = new class {
            public function id() { return 'main::from-toarray.jpg'; }
            public function lastModified() { return new \DateTime('@1700000001'); }
            public function url() { return '/assets/main/from-toarray.jpg'; }
        };

        $both = new class($iteratedAsset, $arrayableAsset) implements \IteratorAggregate, \Illuminate\Contracts\Support\Arrayable {
            public function __construct(private object $fromIter, private object $fromArr) {}
            public function getIterator(): \Iterator { return new \ArrayIterator([$this->fromIter]); }
            public function toArray(): array { return [$this->fromArr]; }
        };

        $resolver = new ImageResolver(assetLookup: fn () => null);

        $resolved = $resolver->resolve($both);

        $this->assertNotNull($resolved);
        $this->assertSame('main::from-iterator.jpg', $resolved->id);
    }
}
