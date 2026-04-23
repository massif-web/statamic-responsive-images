<?php

namespace Massif\ResponsiveImages\View;

use Closure;
use Statamic\View\Antlers\Language\Runtime\StackReplacementManager;

class Preloader
{
    /** @var Closure|null */
    private $pusher;

    public function __construct(?Closure $pusher = null)
    {
        $this->pusher = $pusher;
    }

    public function push(string $srcset, string $sizes, string $mimeType): void
    {
        if ($srcset === '' || $mimeType === '') {
            return;
        }

        $link = sprintf(
            '<link rel="preload" as="image" imagesrcset="%s" imagesizes="%s" type="%s" fetchpriority="high">',
            $this->e($srcset),
            $this->e($sizes),
            $this->e($mimeType),
        );

        if ($this->pusher) {
            ($this->pusher)('head', $link);
            return;
        }

        StackReplacementManager::pushStack('head', $link);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
