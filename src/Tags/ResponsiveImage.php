<?php

namespace Massif\ResponsiveImages\Tags;

use Illuminate\Support\Facades\Log;
use Statamic\Tags\Tags;
use Massif\ResponsiveImages\Image\FormatPolicy;
use Massif\ResponsiveImages\Image\ImageMetadata;
use Massif\ResponsiveImages\Image\ImageResolver;
use Massif\ResponsiveImages\Image\Metadata;
use Massif\ResponsiveImages\Image\SrcsetBuilder;
use Massif\ResponsiveImages\Image\UrlBuilder;
use Massif\ResponsiveImages\Image\Placeholder;
use Massif\ResponsiveImages\Image\ResolvedImage;
use Massif\ResponsiveImages\View\PassthroughRenderer;
use Massif\ResponsiveImages\View\PictureRenderer;
use Massif\ResponsiveImages\View\Preloader;

class ResponsiveImage extends Tags
{
    protected static $handle = 'responsive_image';

    private const PASSTHROUGH_KEYS = [
        'bg', 'blur', 'brightness', 'contrast', 'filter',
        'flip', 'gamma', 'orient', 'pixelate', 'sharpen',
    ];

    private ?ImageResolver $resolver;
    private ?Metadata $metadata;
    private ?SrcsetBuilder $srcsetBuilder;
    private ?UrlBuilder $urlBuilder;
    private ?Placeholder $placeholder;
    private ?PictureRenderer $renderer;
    private ?PassthroughRenderer $passthrough;
    private ?Preloader $preloader;
    private ?FormatPolicy $formatPolicy;
    private ?array $config;

    public function __construct(
        ?ImageResolver $resolver = null,
        ?Metadata $metadata = null,
        ?SrcsetBuilder $srcsetBuilder = null,
        ?UrlBuilder $urlBuilder = null,
        ?Placeholder $placeholder = null,
        ?PictureRenderer $renderer = null,
        ?PassthroughRenderer $passthrough = null,
        ?Preloader $preloader = null,
        ?FormatPolicy $formatPolicy = null,
        ?array $config = null,
    ) {
        $this->resolver      = $resolver;
        $this->metadata      = $metadata;
        $this->srcsetBuilder = $srcsetBuilder;
        $this->urlBuilder    = $urlBuilder;
        $this->placeholder   = $placeholder;
        $this->renderer      = $renderer;
        $this->passthrough   = $passthrough;
        $this->preloader     = $preloader;
        $this->formatPolicy  = $formatPolicy;
        $this->config        = $config;
    }

    public function index(): string
    {
        $this->bootDependencies();

        $params = $this->params->all();
        $src = $params['src'] ?? null;

        $image = $this->resolver->resolve($src);

        if ($image === null) {
            Log::warning('[responsive_image] unresolvable src', ['src' => $src]);
            return $this->notFound($src);
        }

        return $this->renderForImage($image, $params);
    }

    public function wildcard(string $tag): string
    {
        $this->bootDependencies();

        $value = $this->context->value($tag);
        if ($value === null || $value === '') {
            return $this->notFound($value);
        }

        $params = $this->params->all();
        $params['src'] = $value;

        return $this->renderFromParams($params);
    }

    public function renderFromParams(array $params): string
    {
        $this->bootDependencies();

        $image = $this->resolver->resolve($params['src'] ?? null);

        if ($image === null) {
            Log::warning('[responsive_image] unresolvable src', ['src' => $params['src'] ?? null]);
            return $this->notFound($params['src'] ?? null);
        }

        return $this->renderForImage($image, $params);
    }

    private function notFound(mixed $src): string
    {
        if (! config('app.debug')) {
            return '';
        }

        // Neutralize comment-breaking sequences before embedding the src.
        $safe = str_replace(['--', '>'], ['—', ''], (string) $src);

        return '<!-- responsive_image: src not found "'
            .htmlspecialchars($safe, ENT_QUOTES, 'UTF-8').'" -->';
    }

    private function bootDependencies(): void
    {
        $this->resolver      ??= app(ImageResolver::class);
        $this->metadata      ??= app(Metadata::class);
        $this->srcsetBuilder ??= app(SrcsetBuilder::class);
        $this->urlBuilder    ??= app(UrlBuilder::class);
        $this->placeholder   ??= app(Placeholder::class);
        $this->renderer      ??= app(PictureRenderer::class);
        $this->passthrough   ??= app(PassthroughRenderer::class);
        $this->preloader     ??= app(Preloader::class);
        $this->formatPolicy  ??= app(FormatPolicy::class);
        $this->config        ??= config('responsive-images');
    }

    private function renderForImage(ResolvedImage $image, array $params): string
    {
        $meta = $this->metadata->for($image);

        if ($meta->failed || $meta->mime === 'image/svg+xml' || $meta->mime === 'image/gif') {
            return $this->passthrough->render($image, $this->passthroughParams($params, $image, $meta));
        }

        $sourceWidth = (int) ($meta->width ?: 1920);

        $alt = $this->resolveAlt($params, $image);

        $ratio = $this->parseRatio($params['ratio'] ?? null);
        $fit = $params['fit']
            ?? ($ratio ? ($this->config['glide']['default_fit'] ?? 'crop_focal') : 'contain');

        $widthsOverride = $this->parseWidths($params['widths'] ?? null);
        $widths = $this->srcsetBuilder->build($sourceWidth, $this->config, $widthsOverride);

        [$imgWidth, $imgHeight] = $this->resolveDimensions($params, $meta, $ratio, $widths);

        // Only pass height to srcset entries when a ratio is enforced. Without
        // a ratio, Glide scales proportionally from width alone and we avoid
        // any interaction with Statamic's auto_crop defaults.
        $srcsetHeight = $ratio ? $imgHeight : null;

        $sizes = (string) ($params['sizes'] ?? $this->config['default_sizes']);

        $qualityOverride = (isset($params['quality']) && is_numeric($params['quality']))
            ? (int) $params['quality']
            : null;
        $activeFormats = $this->formatPolicy->formatsFor(
            $this->parseFormats($params['formats'] ?? null),
            (int) max($widths),
        );

        $extras = $this->extrasFromParams($params);
        $artDirection = $params['sources'] ?? null;

        if (is_array($artDirection) && $artDirection !== []) {
            $sources = $this->buildArtDirectionSources($image, $artDirection, $sizes, $ratio, $fit, $activeFormats, $qualityOverride, $extras);
            $lastEntrySrc = $artDirection[count($artDirection) - 1]['src'] ?? null;
            $fallbackImage = $lastEntrySrc !== null
                ? ($this->resolver->resolve($lastEntrySrc) ?? $image)
                : $image;
        } else {
            $sources = $this->buildFormatSources($image, $widths, $sizes, $srcsetHeight, $fit, $activeFormats, $qualityOverride, $extras);
            $fallbackImage = $image;
        }

        $fallbackWidth = (int) ($this->config['fallback_width'] ?? 828);
        $fallbackQuality = $this->qualityFor('fallback', $qualityOverride);
        $imgSrc = $this->urlBuilder->build(
            $fallbackImage,
            width: $fallbackWidth,
            format: 'fallback',
            quality: $fallbackQuality,
            height: $ratio ? (int) round($fallbackWidth / $ratio) : null,
            fit: $fit,
            extras: $extras,
        );

        $fallbackSrcset = $this->buildSrcset($fallbackImage, $widths, 'fallback', $srcsetHeight, $fit, $qualityOverride, $extras);

        $placeholder = $this->placeholderValue($params, $fallbackImage);
        $backgroundColor = $this->dominantColorValue($params, $fallbackImage);
        $objectPosition = $this->objectPositionFromFocal($fallbackImage);

        $figure = $this->bool($params['figure'] ?? false);
        $ratioWrapper = $this->bool($params['ratio_wrapper'] ?? false);
        $hasWrapper = $figure || $ratioWrapper;

        $imgClass = $params['img_class'] ?? null;
        if (! $hasWrapper && ! empty($params['class'])) {
            $imgClass = trim(($imgClass ? $imgClass.' ' : '').$params['class']);
        }

        $caption = $this->resolveCaption($params, $alt, $figure);

        $preload = $this->bool($params['preload'] ?? false);

        // Preload the highest-priority format's srcset. For art-direction,
        // preload the primary <img>'s fallback srcset (per-breakpoint preload
        // is out of scope).
        if ($preload) {
            $preloadSource = null;
            foreach ($sources as $s) {
                if (empty($s['media'])) {
                    $preloadSource = $s;
                    break;
                }
            }
            if ($preloadSource !== null) {
                $this->preloader->push(
                    srcset: (string) $preloadSource['srcset'],
                    sizes: (string) $preloadSource['sizes'],
                    mimeType: (string) $preloadSource['type'],
                );
            } elseif ($fallbackSrcset !== '') {
                $mime = $meta->mime !== '' ? $meta->mime : 'image/jpeg';
                $this->preloader->push(
                    srcset: $fallbackSrcset,
                    sizes: $sizes,
                    mimeType: $mime,
                );
            }
        }

        $loadingDefault = 'lazy';
        $fetchPriorityDefault = 'auto';

        if ($preload) {
            if (! empty($this->config['preload']['auto_eager']) && ! array_key_exists('loading', $params)) {
                $loadingDefault = 'eager';
            }
            if (! empty($this->config['preload']['auto_priority']) && ! array_key_exists('fetchpriority', $params)) {
                $fetchPriorityDefault = 'high';
            }
        }

        $loading = $params['loading'] ?? $loadingDefault;

        // WHATWG auto-sizes: valid only with loading="lazy". Older browsers ignore
        // the `auto` token and fall through to the next source-size. Applied after
        // the preload block so preload links keep the resolvable sizes.
        $imgSizes = $sizes;
        if (($this->config['markup']['auto_sizes'] ?? true) && $loading === 'lazy') {
            $imgSizes = 'auto, '.$sizes;
            foreach ($sources as &$s) {
                $s['sizes'] = 'auto, '.$s['sizes'];
            }
            unset($s);
        }

        $data = [
            'sources' => $sources,
            'img' => [
                'src'             => $imgSrc,
                'srcset'          => $fallbackSrcset,
                'sizes'           => $imgSizes,
                'width'           => $imgWidth,
                'height'          => $imgHeight,
                'alt'             => $alt,
                'class'           => $imgClass,
                'loading'         => $loading,
                'decoding'        => $params['decoding'] ?? 'async',
                'fetchpriority'   => $params['fetchpriority'] ?? $fetchPriorityDefault,
                'placeholder'     => $placeholder,
                'background_color'=> $backgroundColor,
                'object_position' => $objectPosition,
            ],
            'wrapper' => [
                'figure'        => $figure,
                'ratio_wrapper' => $ratioWrapper,
                'ratio'         => $ratio ? $this->formatRatioString($params['ratio']) : null,
                'caption'       => $caption,
                'class'         => $hasWrapper ? ($params['class'] ?? null) : null,
            ],
        ];

        return $this->renderer->render($data);
    }

    private function buildFormatSources(ResolvedImage $image, array $widths, string $sizes, ?int $height, ?string $fit, array $activeFormats, ?int $qualityOverride, array $extras): array
    {
        $sources = [];
        foreach (['avif', 'webp'] as $format) {
            if (! in_array($format, $activeFormats, true)) {
                continue;
            }
            $srcset = $this->buildSrcset($image, $widths, $format, $height, $fit, $qualityOverride, $extras);
            if ($srcset === '') {
                continue;
            }
            $sources[] = [
                'type'   => 'image/'.$format,
                'srcset' => $srcset,
                'sizes'  => $sizes,
                'media'  => null,
            ];
        }
        return $sources;
    }

    private function buildArtDirectionSources(ResolvedImage $parent, array $entries, string $defaultSizes, ?float $parentRatio, ?string $fit, array $activeFormats, ?int $qualityOverride, array $extras): array
    {
        $result = [];
        foreach ($entries as $entry) {
            $resolved = ($entry['src'] ?? '') !== ''
                ? $this->resolver->resolve($entry['src'])
                : $parent;

            if ($resolved === null) {
                continue;
            }

            $meta = $this->metadata->for($resolved);
            if ($meta->failed) {
                continue;
            }
            $entryRatio = $this->parseRatio($entry['ratio'] ?? null) ?? $parentRatio;
            $widths = $this->srcsetBuilder->build((int) ($meta->width ?: 1920), $this->config);
            $entryFit = $fit ?? ($entryRatio ? 'crop_focal' : 'contain');
            $height = $entryRatio
                ? (int) round((end($widths) ?: 0) / $entryRatio)
                : null;
            $sizes = (string) ($entry['sizes'] ?? $defaultSizes);
            $media = $entry['media'] ?? null;

            foreach ($activeFormats as $format) {
                $srcset = $this->buildSrcset($resolved, $widths, $format, $height, $entryFit, $qualityOverride, $extras);
                if ($srcset === '') {
                    continue;
                }

                $mime = $format === 'fallback'
                    ? ($meta->mime ?: 'image/jpeg')
                    : 'image/'.$format;

                $result[] = [
                    'type'   => $mime,
                    'srcset' => $srcset,
                    'sizes'  => $sizes,
                    'media'  => $media,
                ];
            }
        }
        return $result;
    }

    private function buildSrcset(ResolvedImage $image, array $widths, string $format, ?int $height, ?string $fit, ?int $qualityOverride, array $extras = []): string
    {
        if ($widths === []) {
            return '';
        }

        $quality = $this->qualityFor($format, $qualityOverride);
        $maxWidth = max($widths);
        $parts = [];
        foreach ($widths as $w) {
            $h = $height !== null && $maxWidth > 0
                ? (int) round($w * ($height / $maxWidth))
                : null;

            if ($format === 'avif' && ! $this->formatPolicy->avifWidthAllowed($w, $h)) {
                continue;
            }

            $parts[] = $this->urlBuilder->build($image,
                width: $w, format: $format, quality: $quality, height: $h, fit: $fit,
                extras: $extras,
            ).' '.$w.'w';
        }

        return $parts === [] ? '' : implode(', ', $parts);
    }

    private function placeholderValue(array $params, ResolvedImage $image): ?string
    {
        $raw = $params['placeholder'] ?? null;
        if ($raw === false || $raw === 'false' || $raw === '0') {
            return null;
        }
        return $this->placeholder->dataUri($image, $this->config);
    }

    private function dominantColorValue(array $params, ResolvedImage $image): ?string
    {
        $raw = $params['placeholder'] ?? null;
        if ($raw === false || $raw === 'false' || $raw === '0') {
            return null;
        }

        return $this->placeholder->color($image, $this->config);
    }

    private function parseRatio(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $raw = str_replace(':', '/', (string) $raw);
        $parts = explode('/', $raw);
        if (count($parts) !== 2) {
            return null;
        }
        $a = (float) $parts[0];
        $b = (float) $parts[1];
        return $b > 0 ? $a / $b : null;
    }

    private function formatRatioString(mixed $raw): string
    {
        return str_replace(':', '/', (string) $raw);
    }

    private function parseWidths(mixed $raw): ?array
    {
        if (!$raw) {
            return null;
        }
        if (is_array($raw)) {
            return array_values(array_map('intval', $raw));
        }
        return array_values(array_map('intval', array_filter(array_map('trim', explode(',', (string) $raw)))));
    }

    private function resolveDimensions(array $params, ImageMetadata $meta, ?float $ratio, array $widths): array
    {
        $w = isset($params['width']) ? (int) $params['width'] : null;
        $h = isset($params['height']) ? (int) $params['height'] : null;

        if ($w !== null && $h !== null) {
            return [$w, $h];
        }

        if ($ratio !== null) {
            $w ??= $widths ? (int) max($widths) : $meta->width;
            $h ??= (int) round($w / $ratio);
            return [$w, $h];
        }

        return [$meta->width, $meta->height];
    }

    private function resolveCaption(array $params, string $alt, bool $figure): ?string
    {
        if (! $figure) {
            return null;
        }

        if (array_key_exists('caption', $params)) {
            $raw = $params['caption'];

            if ($raw === false || $raw === 'false' || $raw === '0' || $raw === 0) {
                return null;
            }

            if ($raw !== null && $raw !== '' && $raw !== true && $raw !== 'true') {
                return (string) $raw;
            }
        }

        return $alt !== '' ? $alt : null;
    }

    private function objectPositionFromFocal(ResolvedImage $image): ?string
    {
        if (! $image->isAsset() || $image->asset === null) {
            return null;
        }

        $asset = $image->asset;
        $focus = method_exists($asset, 'get') ? $asset->get('focus') : null;

        if (! is_string($focus) || $focus === '') {
            return null;
        }

        $parts = explode('-', $focus);
        if (count($parts) < 2) {
            return null;
        }

        $x = $parts[0];
        $y = $parts[1];

        if (! is_numeric($x) || ! is_numeric($y)) {
            return null;
        }

        return ((float) $x).'% '.((float) $y).'%';
    }

    private function resolveAlt(array $params, ResolvedImage $image): string
    {
        if (array_key_exists('alt', $params)) {
            $value = (string) $params['alt'];
            if ($value !== '') {
                return $value;
            }
        }

        if ($image->isAsset() && $image->asset !== null) {
            $assetAlt = method_exists($image->asset, 'get') ? (string) $image->asset->get('alt') : '';
            if ($assetAlt !== '') {
                return $assetAlt;
            }
        }

        Log::warning('[responsive_image] missing alt text', ['id' => $image->id]);
        return '';
    }

    private function passthroughParams(array $params, ResolvedImage $image, ImageMetadata $meta): array
    {
        return [
            'alt'           => $this->resolveAlt($params, $image),
            'width'         => isset($params['width']) ? (int) $params['width'] : (int) $meta->width,
            'height'        => isset($params['height']) ? (int) $params['height'] : (int) $meta->height,
            'class'         => $params['class'] ?? null,
            'loading'       => $params['loading'] ?? 'lazy',
            'decoding'      => $params['decoding'] ?? 'async',
            'fetchpriority' => $params['fetchpriority'] ?? null,
        ];
    }

    /**
     * @return list<string>|null
     */
    private function parseFormats(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $list = is_array($raw)
            ? $raw
            : array_filter(array_map('trim', explode(',', (string) $raw)));

        $valid = array_values(array_unique(array_filter(
            array_map('strtolower', array_map('strval', $list)),
            fn (string $f) => in_array($f, ['avif', 'webp', 'fallback'], true)
        )));

        return $valid === [] ? null : $valid;
    }

    private function qualityFor(string $format, ?int $override): int
    {
        if ($override !== null) {
            return $override;
        }
        return (int) ($this->config['formats'][$format]['quality'] ?? 82);
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, mixed>
     */
    private function extrasFromParams(array $params): array
    {
        $out = [];
        foreach (self::PASSTHROUGH_KEYS as $k) {
            if (array_key_exists($k, $params) && $params[$k] !== '' && $params[$k] !== null) {
                $out[$k] = $params[$k];
            }
        }
        return $out;
    }
}
