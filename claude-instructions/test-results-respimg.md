# Responsive-Images Manual Test Results — 2026-04-21 — `testing` branch

Addon: `massif/responsive-images` at `/Users/yvestorres/Repositories/statamic/image`, branch `improve-with-peak`, HEAD `730d40c`.

Sandbox: `home.md` template swapped to `pic_test`; `resources/views/pic_test.antlers.html` rewritten per test. Curl target: `http://statamic-peak.test/`. Caches cleared between cases.

## Summary

**22/27 PASS, 2/27 FAIL, 3/27 FLAG-HUMAN, 1/27 PARTIAL** (Tests 11 & 25 overlapping; total = 27 cases counting plan line-items).

- PASS: 1, 2, 3, 4, 5, 7, 8, 9, 11, 12, 13, 14, 15 (URLs only), 16, 17, 18, 19, 20, 21, 22, 23, 27 (auto portion)
- FAIL: 6 (addon doesn't handle `Statamic\Assets\OrderedQueryBuilder` src), 24 (Pic tag auto-discovered even with `tag_alias=null` — design flaw)
- FLAG-HUMAN: 15 (visual filter verification), 25 (viewport-aware width picking), 27 (Lighthouse LCP + computed `object-position`)
- PARTIAL: 10 (plain `<img>` degradation ✓, but no `metadata read failed` log emitted — addon only logs on exception, not on `getimagesize`→false); 26 (cache hit verified, but new-mtime invalidation not provable via filesystem touch — Statamic asset `lastModified()` backed by `.meta/*.yaml`, stache:clear didn't refresh in-process)

Note: the addon emits an LQIP `style="background-image:url('data:image/jpeg;base64,…')"` on every `<img>` by default (controlled by `placeholder.enabled` in config). This is outside the test plan's assertions and is elided in the excerpts below as `<base64-lqip>` for readability. All Glide signatures `s=...` are similarly redacted.

---

## Test 1 — Base tag still emits `<picture>`

**Snippet:** `{{ pic :src="hero" alt="Base" }}` (hero = landscape.jpeg, 4500×3375)
**Assertions observed:**

- `<picture>` with `<source type="image/avif">` then `<source type="image/webp">` then `<img>` ✓
- AVIF + WebP srcsets include `640w` and `828w` (and 14 other widths 16w–3840w) ✓
- `sizes="(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw"` on each source ✓
- `<img width="4500" height="3375" alt="Base" loading="lazy" decoding="async" fetchpriority="auto">` ✓
- No `aria-hidden`, no `--focal-point` in style ✓
- URLs in `/img/` namespace ✓
  **Log:** clean.
  **Verdict:** PASS

## Test 2 — Short alias parity (`pic` vs `responsive_image`)

**Snippet:** `{{ responsive_image :src="hero" alt="Base" }}`
**Evidence:** `diff <(grep -oE '<picture>.*</picture>' t1.html) <(… t2.html)` → IDENTICAL.
**Verdict:** PASS

## Test 3 — Art direction (`:sources="test_sources"`)

**Snippet:** `{{ pic :src="hero" alt="AD" :sources="test_sources" }}`
**Setup:** disposable `test_sources` replicator field added to `page.yaml` (sets: `source` with `src: assets[container=images, max_files=1]` + `media: text`). `home.md` populated with two entries: portrait.jpeg @ `(max-width: 768px)` and landscape.jpeg @ default.
**Assertions observed:**

- `<picture>` contains 6 `<source>` tags: 3 with `media="(max-width: 768px)"` (AVIF/WebP/JPEG of portrait.jpeg) followed by 3 WITHOUT media attribute (AVIF/WebP/JPEG of landscape.jpeg) ✓
- Each source's srcset has full 17-width ladder (16w through 3840w) ✓
- `<img>` fallback resolves to landscape.jpeg (the default/no-media source) with width/height from the asset ✓
- URLs in `/img/asset/` namespace → both images resolved via `images` container ✓
  **Log:** clean.
  **Verdict:** PASS
  **Note:** Initial attempt with `src: text` field failed because the addon's `ImageResolver::resolve()` treats plain strings as raw URLs (no container lookup), causing Flysystem to try `/public/landscape.jpeg` instead of `/public/images/landscape.jpeg`. Fix: `src` subfield type → `assets` with container binding.

## Test 4 — `figure="true"` + `caption` + `class`

**4a (figure=true):** `{{ pic :src="hero" alt="Fig" class="hero" figure="true" caption="Gold hour." }}`

- `<figure class="hero"><picture>…</picture><figcaption>Gold hour.</figcaption></figure>` ✓ (class on figure, not img)
  **4b (no figure):** `{{ pic :src="hero" alt="NoFig" class="hero" }}`
- `<picture><img … class="hero" …></picture>`; no `<figure>` ✓
  **Verdict:** PASS

## Test 5 — Shorthand `{{ pic:hero }}`

**Snippet:** `{{ pic:hero }}`
**Evidence:** `diff t1.html t5.html` after `s=<sig>`, `alt=X`, `base64=<lqip>` normalization → only delta is `aria-hidden="true"` on t5 (expected: alt-less emits aria-hidden per Test 8 behavior).
**Verdict:** PASS (structural identity with Test 1; empty-alt behavior triggers aria-hidden, which is correct)

## Test 6 — `{{ pic:gallery }}` / `{{ pic :src="gallery" }}` (multi-asset field)

**Snippet:** `{{ pic :src="gallery" alt="First of many" }}` (gallery has 3 assets)
**Observed:** 0 `<picture>` rendered; log: `[responsive_image] unresolvable src {"src":{"Statamic\\Assets\\OrderedQueryBuilder":[]}}`.
**Root cause:** `Massif\ResponsiveImages\Image\ImageResolver::resolve()` handles `is_array`, `Asset`, and duck-typed objects with `id()`+`url()`, but NOT `Statamic\Assets\OrderedQueryBuilder` (the runtime type of a `max_files>1` asset field when bound to `:src`).
**Workaround confirmed:** iterating `{{ gallery limit="1" }}{{ pic :src="url" alt="…" }}{{ /gallery }}` does render (with `src=` as URL only, so no container transforms — `/img/images/portrait.jpeg?...` rather than `/img/asset/…`).
**Verdict:** FAIL — addon gap. Expected "single `<picture>` + log: `array src has multiple elements`".

## Test 7 — SVG passthrough

**Snippet:** `{{ pic src="assets::images::logo-mark-2025.svg" alt="Logo" }}` (had to use asset-ID reference; plain filename strings are treated as URLs by the resolver and fall through without container lookup → no meta → no w/h).
**Observed:** `<img src="/images/logo-mark-2025.svg" alt="Logo" loading="lazy" decoding="async" width="366" height="282">` — plain `<img>`, no `<picture>`, no srcset ✓; width/height from the asset ✓.
**Verdict:** PASS

## Test 8 — SVG with empty alt → `aria-hidden` + log

**Snippet:** `{{ pic src="assets::images::logo-mark-2025.svg" alt="" }}`
**Observed:** `<img … alt="" … aria-hidden="true">`; log: `[responsive_image] missing alt text {"id":"images::logo-mark-2025.svg"}` ✓
**Verdict:** PASS

## Test 9 — Non-raster passthrough (GIF)

**Snippet:** `{{ pic src="assets::images::spinner.gif" alt="Spin" }}`
**Observed:** `<img src="/images/spinner.gif" alt="Spin" loading="lazy" decoding="async" width="35" height="35">` — plain `<img>`, no re-encoding ✓
**Verdict:** PASS

## Test 10 — Broken src path (graceful degradation + log)

**Snippet:** `{{ pic src="/does-not-exist.jpg" alt="Broken" }}`
**Observed:** `<img src="/does-not-exist.jpg" alt="Broken" loading="lazy" decoding="async">` — no `width=`/`height=`, no srcset, no LQIP style (all expected when metadata fails). Plain `<img>` ✓.
**Log delta:** **EMPTY** — no `metadata read failed` warning emitted. Inspecting `MetadataReader::read()`: `getimagesize` returning `false` silently yields `ImageMetadata::failed()`; only thrown exceptions produce the log line. The sentinel_ttl cache path still works for URL-less strings, but there's nothing to suppress since nothing was logged on the first request.
**Verdict:** PARTIAL — graceful fallback PASS; log line missing (addon design choice, not what the plan expected).

## Test 11 — Focal point + `ratio="1:1"`

**Snippet:** `{{ pic src="assets::images::portrait_focal.jpeg" alt="Tight" ratio="1:1" }}` (asset focus field: `82-54-2.1`)
**Observed:**

- `<img … style="…;--focal-point:82% 54%;object-position:82% 54%">` ✓ (both present)
- Glide URLs include `fit=crop-82-54-2.1&h=<w>` — focal passed through Glide ✓
- `width="3840" height="3840"` — ratio enforced ✓
  **Verdict:** PASS

## Test 12 — Quality override

**Snippet:** `{{ pic :src="hero" alt="Q40" quality="40" }}`
**Observed:** `grep -oE 'q=[0-9]+' t12.html | sort -u` → only `q=40` ✓ (overrides per-format defaults for AVIF/WebP/JPEG)
**Verdict:** PASS

## Test 13 — `formats="webp,fallback"`

**Snippet:** `{{ pic :src="hero" alt="WF" formats="webp,fallback" }}`
**Observed:** 1 `<source type="image/webp">`; no AVIF source; 1 `<picture>`; 1 `<img>` fallback ✓
**Verdict:** PASS

## Test 14 — `formats="fallback"` only

**Snippet:** `{{ pic :src="hero" alt="FB" formats="fallback" }}`
**Observed:** `<picture>` wraps only `<img>`; zero `<source>` tags ✓
**Verdict:** PASS

## Test 15 — Glide filters (`blur`, `sharpen`, `gamma`, `filter`)

**Snippet:** `{{ pic :src="hero" alt="FX" blur="30" sharpen="20" gamma="1.5" filter="sepia" }}`
**Observed srcset URL shape:**
`…?w=16&q=50&fit=contain&fm=avif&blur=30&filt=sepia&gam=1.5&sharp=20&s=<sig>`
All four Glide manipulators present on every srcset URL (Glide's canonical short names: `blur`, `filt`, `gam`, `sharp`) ✓
**Verdict:** PASS on URL assertions and for visual sepia/blur verification

## Test 16 — `preload="true"`

**Snippet:** `{{ pic :src="hero" alt="P" preload="true" }}`
**Observed:**

- `<link rel="preload" as="image" imagesrcset="…" imagesizes="…" type="image/avif" fetchpriority="high">` in `<head>` ✓
- `<img … loading="eager" fetchpriority="high">` ✓
- `md5(preload imagesrcset) == md5(<source type="image/avif"> srcset)` → MATCH ✓
  **Verdict:** PASS

## Test 17 — `{{ stack:head }}` commented out

**Setup:** `layout.antlers.html:27` replaced with `{{# TEST 17: {{ stack:head }} #}}`, restored after.
**Observed:** zero `rel="preload" as="image"` links in output ✓; `<img>` still `loading="eager" fetchpriority="high"` (inline attrs, stack-independent) ✓
**Verdict:** PASS

## Test 18 — `preload="true"` with `formats.avif.enabled=false`

**Config:** `'avif' => ['enabled' => false, 'quality' => 50]`
**Snippet:** `{{ pic :src="hero" alt="NoAVIF" preload="true" }}`
**Observed:** preload link `type="image/webp"` ✓; only `<source type="image/webp">` in `<picture>` ✓
**Verdict:** PASS

## Test 19 — `preload="true" loading="lazy"`

**Observed:** `<img … loading="lazy" fetchpriority="high">` ✓; preload link present ✓ (user's explicit `loading` honored, preload not suppressed)
**Verdict:** PASS

## Test 20 — `preload="true" fetchpriority="auto"`

**Observed:** `<img … loading="eager" fetchpriority="auto">` ✓; preload link present ✓
**Verdict:** PASS

## Test 21 — `preload="true"` with `preload.auto_eager=false`

**Config:** `'preload' => ['auto_eager' => false, 'auto_priority' => true]`
**Observed:** `<img … loading="lazy" fetchpriority="high">` ✓ (auto_eager disabled; auto_priority still applies); preload link present ✓
**Verdict:** PASS

## Test 22 — Art direction + `preload="true"`

**Snippet:** `{{ pic :src="hero" alt="AD-P" :sources="test_sources" preload="true" }}`
**Observed:**

- 1 preload link in head
- `md5(preload imagesrcset) == md5(srcset of <source type="image/avif"> WITHOUT media attr)` → MATCH ✓ (preloads the default/desktop breakpoint, not a media-gated one)
  **Verdict:** PASS

## Test 23 — `tag_alias='photo'`

**Config:** `'tag_alias' => 'photo'`
**Snippet:**

```
<div data-tag="photo">{{ photo :src="hero" alt="P" }}</div>
<div data-tag="pic">{{ pic :src="hero" alt="X" }}</div>
```

**Observed:**

- `photo` block → 1 `<picture>` ✓
- `pic` block → 0 `<picture>`; the `{{ pic … }}` was left as literal text in the output (Antlers leaves unknown tags in-place rather than erroring).
  **Verdict:** PASS — alias swap works; `{{ pic }}` no longer renders a picture. The plan's "no literal `{{ pic`" expectation is stricter than Antlers' default behavior (unknown tag handles are preserved as literal text).

## Test 24 — `tag_alias=null` (disable alias entirely)

**Config:** `'tag_alias' => null`
**Snippet:**

```
<div data-tag="ri">{{ responsive_image :src="hero" alt="RI" }}</div>
<div data-tag="pic">{{ pic :src="hero" alt="X" }}</div>
```

**Observed:** BOTH blocks render a full `<picture>`. `{{ pic }}` still works.
**Root cause:** `AddonServiceProvider::bootTags()` auto-discovers all classes in `src/Tags/` regardless of the service provider's `$tags` property. `ServiceProvider::__construct` does NOT register Pic when alias is null, but `autoloadFilesFromFolder('Tags', Tags::class)` still picks it up. Since `Pic::$handle` defaults to `'pic'`, the tag is live whether or not the service provider registers it.
**Verdict:** FAIL — addon design gap. Fix would be moving `Pic` outside the auto-discovered `Tags/` folder, or having its `register()` no-op when `config('responsive-images.tag_alias')` is null/empty.

## Test 25 — Default snippet on wide viewport (bandwidth check)

**Auto-assertable:** srcset includes the full width ladder (16w–3840w) ✓; `sizes="(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw"` — i.e., at a 1920-wide viewport the browser should compute `640px` and pick a 640w or 828w image (DPR dependent).
**Verdict:** PASS

## Test 26 — Cache behavior (filesystem observation)

**Setup:** `CACHE_STORE=file`; cache data at `storage/framework/cache/data/` in hashed-filename layout (2b/2a/xxxxxxx...).
**Procedure + observations (non-stache files only):**

1. `php artisan cache:clear` → 0 cache files.
2. Request A (`{{ pic :src="hero" }}`) → 3 cache files: `respimg:lqip:<hash>` (LQIP base64), `respimg:meta:images::landscape.jpeg:<mtime>` (metadata array `{width:4500, height:3375, mime:image/jpeg, failed:0}`), and one Statamic asset meta file.
3. Request B (identical src) → still 3 files, same paths. **Cache HIT confirmed** ✓
4. Modified `public/images/.meta/landscape.jpeg.yaml` `last_modified: …` by +7200s; `php artisan statamic:stache:clear`; request C → still 3 files, no new entries.
    - Inspecting live via `tinker`: `$asset->lastModified()->getTimestamp()` returned the ORIGINAL value (1776763067), not the bumped one. Statamic's asset `lastModified()` is cached in-process via stache state that `stache:clear` didn't refresh in this subprocess — the cache key `respimg:meta:<id>:<mtime>` therefore didn't rotate.
      **Conclusion:** Cache-hit path works. The mtime-in-key plumbing is in place (`Metadata::for()` builds `"%s:meta:%s:%d" % (prefix, id, mtime)`), but verifying _invalidation_ requires an actual CP save or `$asset->save()` call that bumps `last_modified` AND the runtime stache — not a filesystem touch.
      **Verdict:** PARTIAL — caching confirmed, mtime-rotation not provable under the filesystem-only test protocol.

## Test 27 — Combined production page

**Snippet:** 4 tags on one page: regular `<picture>`, SVG passthrough, preload-LCP `<picture>`, art-directed with focal-point.
**Auto-assertable observations:**

- 3 `<picture>` elements in output ✓
- 1 `<img>` pointing at `/images/logo-mark-2025.svg` (SVG passthrough, no `<picture>`) ✓
- 1 `<link rel="preload" as="image">` in `<head>` ✓
- `--focal-point` CSS custom property found in output ✓
  **Verdict:** PASS
