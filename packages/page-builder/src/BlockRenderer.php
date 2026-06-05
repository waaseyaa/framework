<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder;

/**
 * Renders a decoded {@see Block} tree to clean, structure-and-intent-preserving
 * HTML, applied through a brand token system.
 *
 * Output uses framework-owned semantic classes (`pb-band`, `pb-grid`, `pb-card`,
 * `pb-col`, `pb-iconbox`, `pb-btn`, `pb-image`, `pb-heading`, `pb-gallery`) plus
 * a few CSS custom properties for captured per-element intent (`--pb-min-h`,
 * `--pb-bg-image`, `--pb-img-w`). These are framework classes/tokens, not
 * builder classes: no `elementor-*` / `wp-block-*` markup is emitted, hardcoded
 * builder colors/fonts are dropped, and the consuming theme decides the actual
 * look. Leaf rich text was already cleaned by {@see Html\HtmlCleaner}.
 *
 * It also makes the output cleaner than the source: exactly one `<h1>` per page
 * (the first prominent heading; later source h1s demote to h2), and icons are
 * brand inline SVGs rather than a Font Awesome runtime.
 *
 * Generic and builder-agnostic: any decoder that emits the tree gets this.
 *
 * @api
 */
final class BlockRenderer
{
    private bool $h1Used = false;

    /** @var array<string, string> Map of icon token to inline SVG path content. */
    private const array ICONS = [
        'arrow-right' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'arrow-down' => '<line x1="12" y1="5" x2="12" y2="19"/><polyline points="5 12 12 19 19 12"/>',
        'arrow-up-right' => '<line x1="7" y1="17" x2="17" y2="7"/><polyline points="7 7 17 7 17 17"/>',
        'chevron-right' => '<polyline points="9 6 15 12 9 18"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'external-link' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
    ];

    /**
     * @param list<Block> $blocks
     */
    public function render(array $blocks): string
    {
        $this->h1Used = false;
        $out = '';
        foreach ($blocks as $block) {
            $out .= $this->renderBlock($block);
        }

        return $out;
    }

    private function renderBlock(Block $b): string
    {
        return match ($b->type) {
            Block::BAND => $this->renderBand($b),
            Block::GRID => '<div class="pb-grid">' . $this->renderChildren($b) . '</div>',
            Block::CARD => '<div class="pb-card">' . $this->renderChildren($b) . '</div>',
            Block::COLUMN => '<div class="pb-col">' . $this->renderChildren($b) . '</div>',
            Block::ICON_BOX => $this->renderIconBox($b),
            Block::HEADING => $this->renderHeading($b),
            Block::BUTTON => $this->renderButton($b),
            Block::IMAGE => $this->renderImage($b),
            Block::COMPONENT => $b->html,
            Block::GALLERY => $b->html,
            Block::LIST => $b->html,
            Block::TEXT, Block::HTML => $b->html,
            default => $b->html,
        };
    }

    private function renderChildren(Block $b): string
    {
        $out = '';
        foreach ($b->children as $child) {
            $out .= $this->renderBlock($child);
        }

        return $out;
    }

    private function renderBand(Block $b): string
    {
        $variant = \is_string($b->data['variant'] ?? null) ? $b->data['variant'] : 'plain';
        $layout = ($b->data['layout'] ?? 'stack') === 'grid' ? 'grid' : 'stack';

        $children = $this->renderChildren($b);
        $inner = $layout === 'grid' ? '<div class="pb-grid">' . $children . '</div>' : $children;

        $classes = ['pb-band', 'pb-band--' . $variant];
        $vars = [];

        $minHeight = $b->data['min_height'] ?? null;
        if (\is_int($minHeight) && $minHeight > 0) {
            $classes[] = 'pb-band--tall';
            $vars[] = '--pb-min-h:' . $minHeight . 'px';
        }
        $bgImage = $b->data['bg_image'] ?? null;
        if (\is_string($bgImage) && $bgImage !== '') {
            $classes[] = 'pb-band--image';
            $vars[] = "--pb-bg-image:url('" . $this->esc($bgImage) . "')";
        }
        $alignClass = $this->alignClass($b->data['align'] ?? null);

        $hasChrome = $variant === 'gradient' || $variant === 'soft' || $vars !== [];

        if (!$hasChrome) {
            // Plain band with no chrome: pass layout through without a wrapper strip.
            if ($layout === 'grid') {
                return $inner;
            }

            return '<div class="pb-col' . ($alignClass !== '' ? ' ' . $alignClass : '') . '">' . $children . '</div>';
        }

        $style = $vars !== [] ? ' style="' . \implode(';', $vars) . '"' : '';
        $innerClass = 'pb-band__inner' . ($alignClass !== '' ? ' ' . $alignClass : '');

        return '<section class="' . \implode(' ', $classes) . '"' . $style . '>'
            . '<div class="' . $innerClass . '">' . $inner . '</div></section>';
    }

    private function renderIconBox(Block $b): string
    {
        $title = $this->esc($this->str($b->data['title'] ?? ''));
        $desc = $this->esc($this->str($b->data['desc'] ?? ''));
        $url = $this->esc($this->str($b->data['url'] ?? ''));

        $inner = '';
        if ($title !== '') {
            $inner .= $url !== ''
                ? '<h3 class="pb-iconbox__title"><a href="' . $url . '">' . $title . '</a></h3>'
                : '<h3 class="pb-iconbox__title">' . $title . '</h3>';
        }
        if ($desc !== '') {
            $inner .= '<p class="pb-iconbox__desc">' . $desc . '</p>';
        }

        return $inner === '' ? '' : '<div class="pb-iconbox">' . $inner . '</div>';
    }

    private function renderHeading(Block $b): string
    {
        $level = (int) ($b->data['level'] ?? 2);
        if ($level < 1 || $level > 6) {
            $level = 2;
        }
        // Exactly one <h1> per page: the first source h1 stays h1; later source
        // h1s demote to h2. Cleaner than the typical multi-h1 WordPress page.
        if ($level === 1) {
            if ($this->h1Used) {
                $level = 2;
            } else {
                $this->h1Used = true;
            }
        }
        $text = $this->esc($this->str($b->data['text'] ?? ''));
        if ($text === '') {
            return '';
        }
        $alignClass = $this->alignClass($b->data['align'] ?? null);
        $class = 'pb-heading' . ($alignClass !== '' ? ' ' . $alignClass : '');

        return "<h{$level} class=\"{$class}\">{$text}</h{$level}>";
    }

    private function renderButton(Block $b): string
    {
        $text = $this->esc($this->str($b->data['text'] ?? ''));
        if ($text === '') {
            return '';
        }
        $url = $this->esc($this->str($b->data['url'] ?? '#'));
        $url = $url === '' ? '#' : $url;

        $iconSvg = $this->icon($this->str($b->data['icon'] ?? ''));
        $iconLeft = ($b->data['icon_align'] ?? '') === 'left';
        $class = 'pb-btn' . ($iconSvg !== '' && $iconLeft ? ' pb-btn--icon-left' : '');

        return '<a class="' . $class . '" href="' . $url . '">'
            . '<span class="pb-btn__text">' . $text . '</span>' . $iconSvg . '</a>';
    }

    private function renderImage(Block $b): string
    {
        $url = $this->esc($this->str($b->data['url'] ?? ''));
        if ($url === '') {
            return '';
        }
        $alt = $this->esc($this->str($b->data['alt'] ?? ''));
        $classes = ['pb-image'];
        $align = $this->str($b->data['align'] ?? '');
        if (\in_array($align, ['left', 'center', 'right'], true)) {
            $classes[] = 'pb-image--' . $align;
        }
        $style = '';
        $width = $this->str($b->data['width'] ?? '');
        if ($width !== '') {
            $classes[] = 'pb-image--sized';
            $style = ' style="--pb-img-w:' . $this->esc($width) . '"';
        }

        return '<figure class="' . \implode(' ', $classes) . '"' . $style . '>'
            . '<img src="' . $url . '" alt="' . $alt . '" loading="lazy"></figure>';
    }

    private function icon(string $token): string
    {
        $inner = self::ICONS[$token] ?? '';
        if ($inner === '') {
            return '';
        }

        return '<svg class="pb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . $inner . '</svg>';
    }

    private function alignClass(mixed $align): string
    {
        return match ($align) {
            'center' => 'pb-align-center',
            'right', 'end' => 'pb-align-end',
            'between' => 'pb-align-between',
            'left', 'start' => 'pb-align-start',
            default => '',
        };
    }

    private function str(mixed $value): string
    {
        return \is_string($value) ? $value : (\is_scalar($value) ? (string) $value : '');
    }

    private function esc(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
