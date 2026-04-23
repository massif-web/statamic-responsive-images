# Improve With Peak — Test Follow-ups — Design

**Date:** 2026-04-21
**Status:** Proposed
**Branch:** `improve-with-peak`
**Addon:** `Massif\ResponsiveImages` for Statamic 6
**Supersedes nothing; complements** `2026-04-17-improve-with-peak-design.md`

## Purpose

Close the three concrete gaps uncovered by the 2026-04-21 manual test pass (`claude-instructions/test-results-respimg.md`, 22/27 PASS, 2 FAIL, 1 PARTIAL) before merging `improve-with-peak`. Each fix is narrow, independent, and has a settled shape.

## Goals

- **Fix 1 (Test 6 FAIL)** — `ImageResolver::resolve()` handles any non-Asset iterable (`Traversable` / `Arrayable`) — e.g., `Statamic\Assets\OrderedQueryBuilder` from multi-asset fields — by collapsing to the first element, mirroring the existing array-src behavior.
- **Fix 2 (Test 24 FAIL)** — `tag_alias=null` actually disables the `pic` tag. Achieved by relocating `Pic` out of the auto-scanned `src/Tags/` folder so it's registered exclusively via the service provider's conditional logic.
- **Fix 3 (Test 10 PARTIAL)** — `MetadataReader::read()` emits `Log::warning` with a `reason` field on both `getimagesize() === false` and the exception branch, giving operators a single grep needle.

## Non-Goals

- Answering the `loading="eager"` ↔ `preload` / `fetchpriority` / `decoding` defaults question. Deferred to a separate brainstorm.
- Test 26's cache-mtime invalidation "PARTIAL" — that's a test-protocol limitation (filesystem touch vs. a CP save that bumps runtime stache), not an addon bug.
- Test 10's secondary observation about suppressing repeat failure reads — already handled by `Metadata::for()`'s `sentinel_ttl`; no action needed.
- Any behavior change to Test 6's **existing** array-src unwrap path; it's only extended to cover iterables.

## Approach

**Three isolated, bisectable fixes**, each with its own commit and new PHPUnit coverage. No new abstractions, no config surface changes, no documentation surface beyond CHANGELOG. Shipped in one PR on the current branch.

## File Layout

**Moved:**

- `src/Tags/Pic.php` → `src/Aliases/Pic.php` (new namespace: `Massif\ResponsiveImages\Aliases`).

**Modified:**

- `src/Image/ImageResolver.php` — new detection branch before the Asset / duck-typed check; collapses `Traversable` or `Arrayable` to an array and recurses through the existing array branch.
- `src/Image/MetadataReader.php` — new warning log on `getimagesize() === false`; shared log shape with a `reason` field across both failure paths; defensive `try { Log::... } catch (\RuntimeException) {}` wrap matching the pattern already used in `ImageResolver`.
- `src/ServiceProvider.php` — `use Massif\ResponsiveImages\Aliases\Pic;` instead of `Massif\ResponsiveImages\Tags\Pic;`. No change to registration logic.

**No changes:**

- `config/responsive-images.php`
- `README.md`
- Any other production file.

## Fix 1 — `ImageResolver` iterable collapse

**Root cause.** `ImageResolver::resolve()` currently recognizes `is_array`, `Asset` instances, and duck-typed objects with `id()`+`url()`. Statamic's multi-asset field binding yields a `Statamic\Assets\OrderedQueryBuilder`, which has neither `id()` nor `url()` and is not an array — it falls through the type ladder and the resolver returns `null`, producing zero output and the observed `unresolvable src` log.

**Change.** Insert two new branches between the existing `Asset` check and the duck-typed `id()`+`url()` check. Final detection order in `resolve()`:

1. `null` / empty-string early return — unchanged.
2. `is_array($src)` branch — unchanged (logs "multiple elements", recurses on element 0).
3. `$src instanceof \Statamic\Contracts\Assets\Asset` → Asset branch — unchanged. **Must precede iterable detection** because Statamic Assets may be `Arrayable` in some versions; Asset semantics take priority.
4. **New:** `$src instanceof \Traversable` → coerce via `iterator_to_array($src, false)`, then feed through the existing array branch.
5. **New:** `elseif $src instanceof \Illuminate\Contracts\Support\Arrayable` → coerce via `$src->toArray()`, then feed through the existing array branch.
6. Duck-typed `method_exists('id') && method_exists('url')` — unchanged.
7. String branches (`assets::…` prefix, raw URL fallback) — unchanged.

**Semantics of collapse.** The collapsed array is handed to the same `is_array` path that already exists (line 26–40 of the current file), so:
- Multi-element collapse logs `[responsive_image] array src has multiple elements; using first` at debug level.
- Empty collapse returns `null` (existing behavior for `$src === []`).
- Single-element collapse is silent and recurses on element 0.

**Why `Arrayable` over duck-typed `all()`/`get()`/`toArray()`.** `OrderedQueryBuilder`, Statamic query builders, and Eloquent Collections all implement `Illuminate\Contracts\Support\Arrayable`. Checking the interface is narrower than three `method_exists` checks and less likely to over-match random user objects.

**Fail-silent contract preserved.** If a collapsed element is itself unresolvable (unknown type, empty string), the recursion returns `null` — same as today.

## Fix 2 — `Pic` relocation

**Root cause.** Statamic's `AddonServiceProvider::bootTags()` calls `autoloadFilesFromFolder('Tags', Tags::class)` — it auto-discovers every `Tags`-subclass in `src/Tags/` regardless of what the service provider's `$tags` property contains. `Pic::$handle = 'pic'` is a class property, so the tag registers with handle `pic` whether or not the alias config is set.

**Change.** Move `src/Tags/Pic.php` to `src/Aliases/Pic.php` and change its namespace to `Massif\ResponsiveImages\Aliases`. The `src/Aliases/` folder is a sibling of `src/Tags/` and is not scanned by `autoloadFilesFromFolder('Tags', …)`, so `Pic` is now registered **only** via the `$this->tags[] = Pic::class` line in `ServiceProvider::__construct`, which is gated on a non-empty alias that isn't `'responsive_image'`.

**Resulting behavior matrix:**

| `tag_alias` config | `pic` tag registered? | `responsive_image` tag registered? |
|---|---|---|
| `null` / `''` | No | Yes |
| `'responsive_image'` | No | Yes |
| `'pic'` | Yes (handle=`pic`) | Yes |
| `'photo'` (custom) | Yes (handle=`photo`) | Yes |

**Risk.** Any user code importing `Massif\ResponsiveImages\Tags\Pic` directly would break. This is an addon-internal class, not documented as a public API; the branch is unreleased. Mitigation: CHANGELOG note. No deprecation shim.

## Fix 3 — `MetadataReader` warning on unreadable source

**Root cause.** The non-asset branch of `MetadataReader::read()` calls `@getimagesize($path)`. When that returns `false` (missing file, unreadable, not an image), the code returns `ImageMetadata::failed()` silently. Only the outer `catch (\Exception $e)` path emits a `Log::warning`. Test 10 observed that a broken src produced the expected degraded output but left operators with no log trail.

**Change.** Before `return ImageMetadata::failed();` on the `$info === false` path, emit:

```php
Log::warning('[responsive_image] metadata read failed', [
    'id'     => $image->id,
    'reason' => 'unreadable',
]);
```

Update the existing exception-branch log to add `'reason' => 'exception'` alongside the current `id`/`error` fields. Both call sites wrapped in `try { … } catch (\RuntimeException) { /* no app */ }` mirroring `ImageResolver.php:31–37`.

**Log shape summary:**

| Path | Level | Message | Keys |
|---|---|---|---|
| `getimagesize()` returns `false` | `warning` | `[responsive_image] metadata read failed` | `id`, `reason='unreadable'` |
| Exception thrown | `warning` | `[responsive_image] metadata read failed` | `id`, `reason='exception'`, `error` |

One message string, one level, one `reason` enum to filter on.

## Testing Plan

PHPUnit coverage per fix. No changes to the manual test plan — you re-run Tests 6, 10, 24 yourself after implementation.

**`tests/Unit/ImageResolverTest.php`** — 3 new cases:
- `ArrayIterator` containing two duck-typed Asset stubs → resolves first; debug log observed.
- An `Arrayable` with empty `toArray()` → returns `null`.
- Object implementing both `Traversable` and `Arrayable` → `Traversable` branch wins; result identical to pure-Traversable input.

**`tests/Feature/TagAliasRegistrationTest.php`** (new file) — 3 cases:
- `tag_alias=null` → Antlers input `{{ pic :src="…" }}` renders output containing no `<picture>`; `{{ responsive_image :src="…" }}` renders `<picture>`.
- `tag_alias='pic'` → both render `<picture>`.
- `tag_alias='photo'` → `{{ photo :src="…" }}` renders `<picture>`; `{{ pic :src="…" }}` renders no `<picture>`.

**`tests/Unit/MetadataReaderTest.php`** — 2 new cases plus an update:
- Non-existent file path → returns `ImageMetadata::failed()` and `Log::warning` called once with `reason='unreadable'`.
- Exception path → `Log::warning` called once with `reason='exception'` and `error` populated. If stubbing `@getimagesize` is awkward, extract a small `protected function probe(string $path): array|false` seam on `MetadataReader` so the test can subclass; production code path identical.
- Update any existing expectation on the old log shape to match the new `reason` key.

**Regression:** the full existing suite (`ImageResolverTest`, `MetadataReaderTest`, `ResponsiveImageTagTest`, and ~8 other unit tests) must pass unchanged.

## Sequencing

One commit per fix, smallest to largest blast radius:

1. **Fix 3 first** — `MetadataReader` logging. Zero public-API change. Establishes the `reason` log-field convention (not reused elsewhere yet, but cheap and consistent).
2. **Fix 1** — `ImageResolver` iterable collapse. One-file change, extends an existing test file.
3. **Fix 2 last** — `Pic` relocation. Touches two files, moves a class across namespaces, adds a new test file. Last so earlier commits stay bisectable if a regression surfaces.

No dependencies between the three; this order minimizes blast radius per commit.

## Risks & Mitigations

- **Iterable detection over-matches.** A user object implementing `Arrayable` that wasn't meant as an asset list gets coerced. Failure mode: the collapsed first element falls through to the "unknown type" path and `resolve()` returns `null` — same fail-silent contract as today. Non-crashing.
- **`Pic` namespace move breaks user imports.** Acknowledged. CHANGELOG entry. Branch is unreleased.
- **`Log::warning` test mocks.** Existing tests using `Log::shouldReceive(...)` will still dispatch the call through the defensive try/catch. Will grep existing tests during implementation and adjust any that asserted the old log shape.
- **`Asset` being `Traversable` in some Statamic versions.** Mitigated by ordering: `Asset` branch checked before `Traversable`.

## Documentation

- `CHANGELOG.md` — three bullets under the unreleased section:
  - `Fix: resolve multi-asset field sources (OrderedQueryBuilder / any Traversable | Arrayable) by collapsing to the first asset, mirroring array-src behavior.`
  - `Fix: setting tag_alias=null now correctly disables the pic tag (Pic moved out of auto-scanned src/Tags/).`
  - `Fix: MetadataReader logs a warning on unreadable sources, not only on exceptions.`
- `README.md` — no changes.

## Acceptance Criteria

- New PHPUnit coverage exists for each of the three fixes.
- No regression in the existing unit and feature test suites.
- Manual re-verification of Tests 6, 10, 24 owned by the user, out of scope for this spec.
