# Improve With Peak — Test Follow-ups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the three concrete gaps from the 2026-04-21 manual test pass — `OrderedQueryBuilder` resolution (Test 6), `tag_alias=null` not disabling Pic (Test 24), missing warning on unreadable metadata (Test 10) — before merging `improve-with-peak`.

**Architecture:** Three isolated fixes, one commit per fix, smallest blast radius first. Each fix touches 1–2 source files plus one test file. No config surface changes, no new abstractions.

**Tech Stack:** PHP 8.2, Statamic 6, Laravel (via Statamic), PHPUnit 11, Orchestra Testbench 10.

**Spec:** `docs/superpowers/specs/2026-04-21-improve-with-peak-test-followups-design.md`

---

## File Map

**Modified:**
- `src/Image/MetadataReader.php` — add warning log on `getimagesize() === false`; shared message with `reason` field across both failure paths; defensive `try/catch RuntimeException` wrap around Log calls.
- `src/Image/ImageResolver.php` — insert `Traversable` and `Arrayable` branches between the `Asset` check and the duck-typed check.
- `src/ServiceProvider.php` — change `use Massif\ResponsiveImages\Tags\Pic;` → `use Massif\ResponsiveImages\Aliases\Pic;`.
- `tests/Unit/MetadataReaderTest.php` — add 2 tests covering log shape on `unreadable` and `exception` paths.
- `tests/Unit/ImageResolverTest.php` — add 3 tests covering `Traversable`, `Arrayable`, and the Traversable-wins precedence case.
- `tests/Feature/ResponsiveImageTagTest.php` — update namespace of `Massif\ResponsiveImages\Tags\Pic` to `Massif\ResponsiveImages\Aliases\Pic` in 2 existing tests.
- `CHANGELOG.md` — three bullets in `Unreleased` → `Fixed` subsection.

**Moved:**
- `src/Tags/Pic.php` → `src/Aliases/Pic.php` (namespace: `Massif\ResponsiveImages\Aliases`).

**Created:**
- `tests/Feature/TagAliasRegistrationTest.php` — verifies `$tags` array contents for three config scenarios + file-layout assertion proving Pic is no longer in the auto-scanned folder.

---

## Task 1: MetadataReader warning on unreadable source (Fix 3)

**Files:**
- Modify: `src/Image/MetadataReader.php`
- Modify: `tests/Unit/MetadataReaderTest.php`
- Modify: `CHANGELOG.md`

**Why first:** Smallest blast radius. Zero public-API change. Establishes the `reason` log-field convention used nowhere else yet.

- [ ] **Step 1: Write the two failing log-assertion tests**

Add these two methods to `tests/Unit/MetadataReaderTest.php` at the end of the class (before the closing brace):

```php
    public function test_unreadable_url_logs_warning_with_reason_unreadable(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $reader = new MetadataReader();
        $reader->read(new ResolvedImage(
            asset: null,
            id: 'missing',
            mtime: 1,
            url: '/this/path/does/not/exist.jpg',
        ));

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $ctx) {
                return $message === '[responsive_image] metadata read failed'
                    && ($ctx['reason'] ?? null) === 'unreadable'
                    && ($ctx['id'] ?? null) === 'missing';
            })
            ->once();
    }

    public function test_asset_exception_logs_warning_with_reason_exception(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $asset = $this->getMockBuilder(Asset::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['width'])
            ->getMock();
        $asset->method('width')->willThrowException(new RuntimeException('driver exploded'));

        $reader = new MetadataReader();
        $reader->read(new ResolvedImage(asset: $asset, id: 'bang', mtime: 1, url: null));

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $ctx) {
                return $message === '[responsive_image] metadata read failed'
                    && ($ctx['reason'] ?? null) === 'exception'
                    && ($ctx['id'] ?? null) === 'bang'
                    && ($ctx['error'] ?? null) === 'driver exploded';
            })
            ->once();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'test_unreadable_url_logs_warning_with_reason_unreadable|test_asset_exception_logs_warning_with_reason_exception' tests/Unit/MetadataReaderTest.php`

Expected: both FAIL. The first fails because no log call is made on the `getimagesize()===false` path. The second fails because the current log context has no `reason` key.

- [ ] **Step 3: Implement the source changes**

Replace the entire contents of `src/Image/MetadataReader.php` with:

```php
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
```

- [ ] **Step 4: Run the two new tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'test_unreadable_url_logs_warning_with_reason_unreadable|test_asset_exception_logs_warning_with_reason_exception' tests/Unit/MetadataReaderTest.php`

Expected: both PASS.

- [ ] **Step 5: Run the full MetadataReader suite for regression check**

Run: `vendor/bin/phpunit tests/Unit/MetadataReaderTest.php`

Expected: 6 tests pass (4 pre-existing + 2 new), 0 failures.

- [ ] **Step 6: Run the full test suite for regression check**

Run: `vendor/bin/phpunit`

Expected: all green.

- [ ] **Step 7: Update CHANGELOG**

Edit `CHANGELOG.md`. Under the `## Unreleased` → `### Fixed` section (after the existing "Public-relative URLs" bullet), add a new bullet:

```markdown
- **Metadata read failures now log a warning on both the `getimagesize()` false-return and exception paths.** Previously, unreadable-but-non-throwing sources (missing files, non-images) silently returned an `ImageMetadata::failed()` sentinel with no log trail. Both paths now emit `Log::warning('[responsive_image] metadata read failed', …)` with a `reason` field (`'unreadable'` or `'exception'`) differentiating the two, giving operators a single grep needle.
```

- [ ] **Step 8: Commit**

```bash
git add src/Image/MetadataReader.php tests/Unit/MetadataReaderTest.php CHANGELOG.md
git commit -m "$(cat <<'EOF'
fix(responsive-images): log warning on unreadable metadata source

MetadataReader::read() previously only logged on the exception branch;
a missing or non-image file that made getimagesize() return false
produced an ImageMetadata::failed() sentinel with no log trail. Both
failure paths now emit a single warning message with a 'reason' field
('unreadable' or 'exception') so operators can grep one string.

Closes Test 10 PARTIAL from the 2026-04-21 manual test pass.
EOF
)"
```

---

## Task 2: ImageResolver iterable collapse (Fix 1)

**Files:**
- Modify: `src/Image/ImageResolver.php`
- Modify: `tests/Unit/ImageResolverTest.php`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Write three failing tests**

Add these three methods to `tests/Unit/ImageResolverTest.php` at the end of the class (before the closing brace):

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'test_resolves_traversable_to_first_asset|test_resolves_arrayable_to_first_asset|test_empty_arrayable_returns_null|test_traversable_wins_over_arrayable_when_both_implemented' tests/Unit/ImageResolverTest.php`

Expected: all four FAIL. The Traversable and Arrayable branches currently fall through to the duck-typed check (which neither satisfies) and return `null`.

- [ ] **Step 3: Implement the source change**

Edit `src/Image/ImageResolver.php`. Replace the `resolve()` method (lines 20–68) with:

```php
    public function resolve(mixed $src): ?ResolvedImage
    {
        if ($src === null || $src === '') {
            return null;
        }

        if (is_array($src)) {
            if ($src === []) {
                return null;
            }
            if (count($src) > 1) {
                try {
                    Log::debug('[responsive_image] array src has multiple elements; using first', [
                        'count' => count($src),
                    ]);
                } catch (\RuntimeException) {
                    // No Laravel application container in this context; skip logging.
                }
            }
            return $this->resolve($src[0]);
        }

        if ($src instanceof Asset) {
            return $this->fromAsset($src);
        }

        if ($src instanceof \Traversable) {
            return $this->resolve(iterator_to_array($src, false));
        }

        if ($src instanceof \Illuminate\Contracts\Support\Arrayable) {
            return $this->resolve($src->toArray());
        }

        if (is_object($src) && method_exists($src, 'id') && method_exists($src, 'url')) {
            return $this->fromAsset($src);
        }

        if (is_string($src) && str_starts_with($src, 'assets::')) {
            $asset = $this->lookupAsset($src);
            if ($asset !== null) {
                return $this->fromAsset($asset);
            }
            return null;
        }

        if (is_string($src)) {
            return new ResolvedImage(
                asset: null,
                id:    md5($src),
                mtime: 0,
                url:   $src,
            );
        }

        return null;
    }
```

Note: the `Asset` branch stays **before** `Traversable` / `Arrayable` so Statamic Assets (which may implement `Arrayable` in some versions) take Asset semantics rather than being collapsed.

- [ ] **Step 4: Run the four new tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'test_resolves_traversable_to_first_asset|test_resolves_arrayable_to_first_asset|test_empty_arrayable_returns_null|test_traversable_wins_over_arrayable_when_both_implemented' tests/Unit/ImageResolverTest.php`

Expected: all four PASS.

- [ ] **Step 5: Run the full ImageResolver suite for regression check**

Run: `vendor/bin/phpunit tests/Unit/ImageResolverTest.php`

Expected: 12 tests pass (8 pre-existing + 4 new), 0 failures.

- [ ] **Step 6: Run the full test suite for regression check**

Run: `vendor/bin/phpunit`

Expected: all green.

- [ ] **Step 7: Update CHANGELOG**

Edit `CHANGELOG.md`. Under `## Unreleased` → `### Fixed`, add:

```markdown
- **Multi-asset field sources now resolve.** `ImageResolver::resolve()` previously handled arrays, `Asset` instances, and duck-typed asset objects, but fell through on `Statamic\Assets\OrderedQueryBuilder` (the runtime type of a `max_files>1` asset field bound to `:src`), producing an "unresolvable src" log and no output. The resolver now collapses any non-Asset `Traversable` or `Illuminate\Contracts\Support\Arrayable` to its first element, mirroring the existing array-src unwrap behavior. Collections, iterators, and Statamic query builders all resolve.
```

- [ ] **Step 8: Commit**

```bash
git add src/Image/ImageResolver.php tests/Unit/ImageResolverTest.php CHANGELOG.md
git commit -m "$(cat <<'EOF'
fix(responsive-images): resolve multi-asset field sources

ImageResolver::resolve() now handles any non-Asset Traversable or
Arrayable (OrderedQueryBuilder from multi-asset fields, Collections,
iterators) by collapsing to the first element — same semantics as the
existing array-src unwrap. Asset branch stays first so Statamic Assets
that implement Arrayable in some versions keep Asset semantics.

Closes Test 6 FAIL from the 2026-04-21 manual test pass.
EOF
)"
```

---

## Task 3: Relocate Pic out of auto-scanned Tags folder (Fix 2)

**Files:**
- Move: `src/Tags/Pic.php` → `src/Aliases/Pic.php`
- Modify: `src/ServiceProvider.php`
- Modify: `tests/Feature/ResponsiveImageTagTest.php` (update 2 existing tests)
- Create: `tests/Feature/TagAliasRegistrationTest.php`
- Modify: `CHANGELOG.md`

**Why last:** Largest blast radius — moves a class across namespaces, touches three test files, adds a new test file. Doing it last keeps earlier commits bisectable if a regression surfaces.

- [ ] **Step 1: Write the new registration test file**

Create `tests/Feature/TagAliasRegistrationTest.php` with:

```php
<?php

namespace Massif\ResponsiveImages\Tests\Feature;

use Massif\ResponsiveImages\ServiceProvider;
use Massif\ResponsiveImages\Tags\ResponsiveImage;
use Massif\ResponsiveImages\Tests\TestCase;
use ReflectionProperty;

class TagAliasRegistrationTest extends TestCase
{
    public function test_pic_is_no_longer_in_the_auto_scanned_tags_folder(): void
    {
        // Structural proof: Statamic's AddonServiceProvider::bootTags() scans
        // src/Tags/. Pic must live outside that folder so it's registered
        // exclusively via ServiceProvider::__construct's conditional logic.
        $this->assertFileDoesNotExist(__DIR__.'/../../src/Tags/Pic.php');
        $this->assertFileExists(__DIR__.'/../../src/Aliases/Pic.php');
    }

    public function test_tag_alias_null_does_not_register_pic(): void
    {
        config(['responsive-images.tag_alias' => null]);

        $provider = new ServiceProvider($this->app);
        $tags = $this->readTagsProperty($provider);

        $this->assertContains(ResponsiveImage::class, $tags);
        $this->assertNotContains(\Massif\ResponsiveImages\Aliases\Pic::class, $tags);
    }

    public function test_tag_alias_empty_string_does_not_register_pic(): void
    {
        config(['responsive-images.tag_alias' => '']);

        $provider = new ServiceProvider($this->app);
        $tags = $this->readTagsProperty($provider);

        $this->assertNotContains(\Massif\ResponsiveImages\Aliases\Pic::class, $tags);
    }

    public function test_tag_alias_default_pic_registers_pic(): void
    {
        config(['responsive-images.tag_alias' => 'pic']);

        $provider = new ServiceProvider($this->app);
        $tags = $this->readTagsProperty($provider);

        $this->assertContains(ResponsiveImage::class, $tags);
        $this->assertContains(\Massif\ResponsiveImages\Aliases\Pic::class, $tags);
        $this->assertSame('pic', \Massif\ResponsiveImages\Aliases\Pic::$handle);
    }

    public function test_tag_alias_custom_photo_sets_handle(): void
    {
        config(['responsive-images.tag_alias' => 'photo']);

        $provider = new ServiceProvider($this->app);
        $tags = $this->readTagsProperty($provider);

        $this->assertContains(\Massif\ResponsiveImages\Aliases\Pic::class, $tags);
        $this->assertSame('photo', \Massif\ResponsiveImages\Aliases\Pic::$handle);
    }

    /**
     * AddonServiceProvider's $tags property is protected; read via reflection.
     *
     * @return array<int, class-string>
     */
    private function readTagsProperty(ServiceProvider $provider): array
    {
        $prop = new ReflectionProperty($provider, 'tags');
        $prop->setAccessible(true);
        return (array) $prop->getValue($provider);
    }
}
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/TagAliasRegistrationTest.php`

Expected: all five tests FAIL. `test_pic_is_no_longer_in_the_auto_scanned_tags_folder` fails because `src/Tags/Pic.php` still exists and `src/Aliases/Pic.php` does not. The remaining four fail because `Massif\ResponsiveImages\Aliases\Pic` doesn't exist yet.

- [ ] **Step 3: Create the new file location**

Create `src/Aliases/Pic.php` with:

```php
<?php

namespace Massif\ResponsiveImages\Aliases;

use Massif\ResponsiveImages\Tags\ResponsiveImage;

class Pic extends ResponsiveImage
{
    public static $handle = 'pic';
}
```

- [ ] **Step 4: Delete the old file**

```bash
git rm src/Tags/Pic.php
```

This removes the file and stages the deletion in one step.

- [ ] **Step 5: Update ServiceProvider import**

Edit `src/ServiceProvider.php`. Change line 13:

```php
use Massif\ResponsiveImages\Tags\Pic;
```

to:

```php
use Massif\ResponsiveImages\Aliases\Pic;
```

No other changes to `ServiceProvider.php` — the registration logic in `__construct` already does the right thing and references `Pic::class` which now resolves to the new namespace via the updated `use`.

- [ ] **Step 6: Update two existing tests that reference the old namespace**

Edit `tests/Feature/ResponsiveImageTagTest.php`.

Replace the contents of `test_pic_alias_produces_same_output_as_responsive_image` (starting at line 422) — change the `\Massif\ResponsiveImages\Tags\Pic` reference on line 447 to `\Massif\ResponsiveImages\Aliases\Pic`. The full method should read:

```php
    public function test_pic_alias_produces_same_output_as_responsive_image(): void
    {
        $cache = new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore);

        $reader = new class extends MetadataReader {
            public function read(ResolvedImage $image): ImageMetadata
            {
                return new ImageMetadata(1600, 900, 'image/jpeg');
            }
        };

        $resolver    = new ImageResolver(assetLookup: fn () => null);
        $metadata    = new Metadata($reader, $cache);
        $srcset      = new SrcsetBuilder();
        $urls        = new UrlBuilder(urlFactory: function ($img, $params) {
            ksort($params);
            return '/img/'.$img->id.'?'.http_build_query($params);
        });
        $placeholder = new Placeholder(cache: $cache, fetcher: fn () => ['bytes' => 'P', 'mime' => 'image/jpeg']);

        $baseConfig = require __DIR__.'/../../config/responsive-images.php';

        $args = [$resolver, $metadata, $srcset, $urls, $placeholder, new PictureRenderer(), new \Massif\ResponsiveImages\View\PassthroughRenderer(), new \Massif\ResponsiveImages\View\Preloader()];

        $tag = new ResponsiveImage(...$args, config: $baseConfig);
        $pic = new \Massif\ResponsiveImages\Aliases\Pic(...$args, config: $baseConfig);

        $params = ['src' => '/p.jpg', 'alt' => 'x'];

        $this->assertSame($tag->renderFromParams($params), $pic->renderFromParams($params));
    }
```

And update `test_pic_default_handle_is_pic` (starting at line 454) — change both the method reference and assertion target:

```php
    public function test_pic_default_handle_is_pic(): void
    {
        $this->assertSame('pic', \Massif\ResponsiveImages\Aliases\Pic::$handle);
    }
```

- [ ] **Step 7: Run the new registration tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/TagAliasRegistrationTest.php`

Expected: all 5 tests PASS.

- [ ] **Step 8: Run the updated existing feature tests**

Run: `vendor/bin/phpunit --filter 'test_pic_alias_produces_same_output_as_responsive_image|test_pic_default_handle_is_pic' tests/Feature/ResponsiveImageTagTest.php`

Expected: both PASS.

- [ ] **Step 9: Run the full test suite for regression check**

Run: `vendor/bin/phpunit`

Expected: all green. If any test fails, the most likely cause is a stray `Massif\ResponsiveImages\Tags\Pic` reference. Grep for it:

Run: `grep -rn 'Tags\\\\Pic\|Tags/Pic' src/ tests/`

Expected: no results.

- [ ] **Step 10: Update CHANGELOG**

Edit `CHANGELOG.md`. Under `## Unreleased` → `### Fixed`, add:

```markdown
- **`tag_alias=null` now correctly disables the `pic` tag.** Previously the `pic` tag remained registered even when the alias was explicitly disabled, because Statamic's `AddonServiceProvider::bootTags()` auto-discovers every class in `src/Tags/`. `Pic` has been relocated to `src/Aliases/Pic.php` (namespace `Massif\ResponsiveImages\Aliases`) so it's registered exclusively via the service provider's conditional logic — present only when `tag_alias` is a non-empty, non-`responsive_image` string.
```

And under `## Unreleased` add a `### Breaking` subsection entry (if one doesn't already exist; otherwise append to it):

```markdown
- **`Massif\ResponsiveImages\Tags\Pic` has moved to `Massif\ResponsiveImages\Aliases\Pic`.** Internal class; unlikely to affect users. Branch is unreleased.
```

- [ ] **Step 11: Commit**

```bash
git add src/Aliases/Pic.php src/ServiceProvider.php tests/Feature/TagAliasRegistrationTest.php tests/Feature/ResponsiveImageTagTest.php CHANGELOG.md
git commit -m "$(cat <<'EOF'
fix(responsive-images): tag_alias=null now disables pic tag

Statamic's AddonServiceProvider::bootTags() auto-discovers every class
in src/Tags/, so Pic was registered even when the ServiceProvider
declined to add it to $tags. Moved Pic to src/Aliases/Pic.php (new
namespace Massif\ResponsiveImages\Aliases) so it's registered
exclusively via the conditional logic in ServiceProvider::__construct.

Closes Test 24 FAIL from the 2026-04-21 manual test pass.
EOF
)"
```

Verify the commit includes both the deletion of `src/Tags/Pic.php` and the creation of `src/Aliases/Pic.php`:

Run: `git show --stat HEAD | grep Pic.php`

Expected: two lines showing delete of `src/Tags/Pic.php` and create of `src/Aliases/Pic.php`.

---

## Final Verification

- [ ] **Step 1: Full test suite green**

Run: `vendor/bin/phpunit`

Expected: all tests pass. Prior count was pre-existing tests; now expect:
- `ImageResolverTest`: 12 tests (was 8)
- `MetadataReaderTest`: 6 tests (was 4)
- `TagAliasRegistrationTest`: 5 tests (new)
- All other suites unchanged.

- [ ] **Step 2: Commit log is clean and bisectable**

Run: `git log --oneline main..HEAD | head -10`

Expected: the three new fix commits appear at the top (Task 3 most recent, Task 1 oldest), each with a self-contained diff.

- [ ] **Step 3: No stray references to the old Pic namespace**

Run: `grep -rn 'ResponsiveImages\\\\Tags\\\\Pic\|Tags/Pic' .` (from repo root, excluding `.git` and `vendor`)

A cleaner invocation:

Run: `git grep -n 'ResponsiveImages\\\\Tags\\\\Pic'`

Expected: no results.

- [ ] **Step 4: Manual re-verification (user-owned, out of scope for this plan)**

The user re-runs Tests 6, 10, 24 from `claude-instructions/test-results-respimg.md` in their Peak sandbox at `http://statamic-peak.test/`. Out of scope for this plan per the spec's acceptance criteria.
