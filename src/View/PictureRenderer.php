<?php

namespace Massif\ResponsiveImages\View;

class PictureRenderer
{
    public function render(array $data): string
    {
        $picture = $this->renderPicture($data['sources'], $data['img']);

        $wrapper = $data['wrapper'];

        if ($wrapper['ratio_wrapper'] && $wrapper['ratio']) {
            $wrapperClass = $wrapper['figure'] ? null : $wrapper['class'];
            $picture = sprintf(
                '<div style="aspect-ratio:%s"%s>%s</div>',
                $this->e($wrapper['ratio']),
                $wrapperClass ? ' class="'.$this->e($wrapperClass).'"' : '',
                $picture
            );
        }

        if ($wrapper['figure']) {
            $classAttr = $wrapper['class'] ? ' class="'.$this->e($wrapper['class']).'"' : '';
            $caption = $wrapper['caption']
                ? '<figcaption>'.$this->e($wrapper['caption']).'</figcaption>'
                : '';
            return "<figure{$classAttr}>{$picture}{$caption}</figure>";
        }

        return $picture;
    }

    private function renderPicture(array $sources, array $img): string
    {
        $parts = ['<picture>'];

        foreach ($sources as $s) {
            $attrs = sprintf(
                ' type="%s" srcset="%s" sizes="%s"',
                $this->e($s['type']),
                $this->e($s['srcset']),
                $this->e($s['sizes'])
            );
            if (!empty($s['media'])) {
                $attrs .= ' media="'.$this->e($s['media']).'"';
            }
            $parts[] = '<source'.$attrs.'>';
        }

        $parts[] = $this->renderImg($img);
        $parts[] = '</picture>';

        return implode('', $parts);
    }

    private function renderImg(array $img): string
    {
        $attrs = [
            'src'           => $img['src'],
            'srcset'        => $img['srcset'],
            'sizes'         => $img['sizes'],
            'width'         => (string) $img['width'],
            'height'        => (string) $img['height'],
            'alt'           => (string) $img['alt'],
            'loading'       => $img['loading'],
            'decoding'      => $img['decoding'],
            'fetchpriority' => $img['fetchpriority'],
        ];

        if (!empty($img['class'])) {
            $attrs['class'] = $img['class'];
        }

        if (!empty($img['placeholder'])) {
            $safeUri = preg_replace('/[^A-Za-z0-9+\/=:;,.\-]/', '', (string) $img['placeholder']);
            $attrs['style'] = "background-size:cover;background-image:url('".$safeUri."')";
        }

        $rendered = '';
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === '') {
                if ($k !== 'alt') {
                    continue;
                }
            }
            $rendered .= ' '.$k.'="'.($k === 'style' ? $v : $this->e((string) $v)).'"';
        }

        return '<img'.$rendered.'>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
