<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Html;

/**
 * Converts raw HTML that carries its own scoped `<style>` block (a common shape
 * for content pasted into a WordPress text/HTML widget) into a clean, owned
 * component: sanitized markup plus CSS that is re-namespaced under a generated
 * scope class.
 *
 * This is the generic answer to "raw HTML + CSS" content. Instead of discarding
 * the CSS and flattening the markup to text (which destroys cards, grids, and
 * portals), the component keeps the structure and its styling, but the styling
 * becomes OWNED: every rule is prefixed with a generated `.pbcXXXXXXXX` scope so
 * it can never leak globally and is regenerable from source. The guarantee
 * shifts from "no classes" to "no un-owned classes".
 *
 * Safety: scripts, iframes, objects, forms, inline event handlers, inline style
 * attributes, and `javascript:` URLs are removed; global `html`/`body` rules are
 * dropped; WordPress shortcodes (no static value) are stripped.
 *
 * Builder-agnostic: nothing here knows about Elementor or any specific builder.
 *
 * @api
 */
final class HtmlComponent
{
    private const array DROP_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'form', 'link', 'meta', 'base'];

    /**
     * Build a component from raw HTML, or return null when the HTML carries no
     * `<style>` block (in which case ordinary cleaning is the right path).
     *
     * @return array{html: string, css: string, scope: string}|null
     */
    public static function fromHtml(string $html): ?array
    {
        if (\stripos($html, '<style') === false) {
            return null;
        }

        $css = '';
        $htmlNoStyle = (string) \preg_replace_callback(
            '/<style[^>]*>(.*?)<\/style>/is',
            static function (array $m) use (&$css): string {
                $css .= "\n" . $m[1];

                return '';
            },
            $html,
        );

        // WordPress' editor often stores the <style> body with HTML artifacts
        // (TinyMCE inserts <br> between lines and a bookmark <span>/BOM). Real
        // CSS contains no HTML, so strip tags, entities, and zero-width chars.
        $css = \strip_tags($css);
        $css = \html_entity_decode($css, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $css = (string) \preg_replace('/[\x{FEFF}\x{200B}]/u', '', $css);
        $css = \trim($css);
        if ($css === '') {
            return null;
        }

        $markup = self::sanitize($htmlNoStyle);
        if (\trim(\strip_tags($markup)) === '' && \stripos($markup, '<img') === false) {
            return null;
        }

        // Repair wrapper elements that an upstream editor (WordPress/TinyMCE/
        // Elementor) stripped but whose own CSS still declares them. Driven only
        // by the source's stylesheet contract; adds no content. See
        // self::reconstructStrippedWrappers().
        $markup = self::reconstructStrippedWrappers($markup, $css);

        $scope = 'pbc' . \substr(\hash('sha1', $css . $markup), 0, 8);
        $scopedCss = self::scopeCss($css, $scope);

        $componentHtml = '<style>' . $scopedCss . '</style>'
            . '<div class="pb-component ' . $scope . '">' . $markup . '</div>';

        return ['html' => $componentHtml, 'css' => $scopedCss, 'scope' => $scope];
    }

    /**
     * Remove dangerous tags/attributes but keep structural markup and class/id
     * (the scoped CSS depends on them). Also strip WordPress shortcodes.
     */
    private static function sanitize(string $html): string
    {
        // Strip shortcodes first (dynamic, no static value).
        $html = (string) \preg_replace('/\[\/?[a-zA-Z][a-zA-Z0-9_\-]*(?:[^\]]*)\]/', '', $html);
        if (\trim($html) === '') {
            return '';
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = \libxml_use_internal_errors(true);
        $loaded = @$dom->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__pbc_root__">' . $html . '</div>',
            \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD,
        );
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return \trim(\strip_tags($html));
        }

        $root = $dom->getElementById('__pbc_root__') ?? $dom->documentElement;
        if (!$root instanceof \DOMElement) {
            return \trim(\strip_tags($html));
        }

        self::filter($root);

        $out = '';
        foreach (\iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        return \trim($out);
    }

    private static function filter(\DOMElement $element): void
    {
        foreach (\iterator_to_array($element->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = \strtolower($child->tagName);
            if (\in_array($tag, self::DROP_TAGS, true)) {
                $child->parentNode?->removeChild($child);
                continue;
            }
            // Strip dangerous attributes; keep class/id and normal content attrs.
            $names = [];
            foreach ($child->attributes as $attr) {
                $names[] = $attr->name;
            }
            foreach ($names as $name) {
                $lower = \strtolower($name);
                if (\str_starts_with($lower, 'on') || $lower === 'style') {
                    $child->removeAttribute($name);
                    continue;
                }
                if (\in_array($lower, ['href', 'src'], true)) {
                    $val = \trim($child->getAttribute($name));
                    if (\stripos($val, 'javascript:') === 0) {
                        $child->removeAttribute($name);
                    }
                }
            }
            self::filter($child);
        }
    }

    /**
     * Prefix every CSS selector with the scope class so the styles are owned and
     * cannot leak globally. Drops `html`/`body`-targeting rules; recurses into
     * `@media`/`@supports`; passes other at-rules through unchanged.
     */
    public static function scopeCss(string $css, string $scope): string
    {
        $css = (string) \preg_replace('!/\*.*?\*/!s', '', $css);
        $len = \strlen($css);
        $pos = 0;
        $out = '';

        while ($pos < $len) {
            $brace = \strpos($css, '{', $pos);
            $semi = \strpos($css, ';', $pos);

            // A top-level statement at-rule (e.g. @import ...;) with no block.
            if ($semi !== false && ($brace === false || $semi < $brace)) {
                $stmt = \trim(\substr($css, $pos, $semi - $pos + 1));
                if ($stmt !== '' && $stmt[0] === '@') {
                    $out .= $stmt;
                }
                $pos = $semi + 1;
                continue;
            }
            if ($brace === false) {
                break;
            }

            $prelude = \trim(\substr($css, $pos, $brace - $pos));
            $blockStart = $brace + 1;
            $blockEnd = self::matchBrace($css, $brace);
            $body = \substr($css, $blockStart, $blockEnd - $blockStart);
            $pos = $blockEnd + 1;

            if ($prelude !== '' && $prelude[0] === '@') {
                $at = \strtolower(\strtok($prelude, " \t("));
                if ($at === '@media' || $at === '@supports') {
                    $out .= $prelude . '{' . self::scopeCss($body, $scope) . '}';
                } else {
                    // @keyframes / @font-face / @page etc: pass through unscoped.
                    $out .= $prelude . '{' . $body . '}';
                }
                continue;
            }

            $selectors = [];
            foreach (\explode(',', $prelude) as $sel) {
                $sel = \trim($sel);
                if ($sel === '') {
                    continue;
                }
                if (\preg_match('/^(html|body)\b/i', $sel) === 1) {
                    continue; // drop global/theme rules
                }
                $selectors[] = '.' . $scope . ' ' . $sel;
            }
            if ($selectors !== []) {
                $out .= \implode(',', $selectors) . '{' . $body . '}';
            }
        }

        return \trim($out);
    }

    /** Index of the `}` matching the `{` at $open. */
    private static function matchBrace(string $css, int $open): int
    {
        $depth = 0;
        $len = \strlen($css);
        for ($i = $open; $i < $len; $i++) {
            $c = $css[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $len - 1;
    }

    /**
     * Reconstruct repeating wrapper elements that an upstream editor stripped but
     * whose own scoped CSS still describes them.
     *
     * Pasted-HTML content frequently loses block wrappers: a hand-built card grid
     * authored as `<div class="grid"><div class="card"><div class="icon">…` is
     * saved by WordPress/TinyMCE/Elementor as a flat run
     * `<div class="grid"><div class="icon">…<div class="ttl">…` with the `.card`
     * boxes gone. The CSS survives intact, so its descendant selectors
     * (`.card .icon`, `.card .ttl`, …) are a machine-readable declaration that
     * `.icon`/`.ttl`/… were meant to live inside a `.card`. This re-groups the
     * flat children back into the missing wrapper using only that contract.
     *
     * Strictly gated, so it never fires on well-formed markup:
     *   - the wrapper class W must be a styled subject in the CSS (`… .W { … }`),
     *   - W must be ABSENT from the DOM (present wrappers are already correct),
     *   - W must have ≥1 descendant class (from `… .W .child { … }` selectors)
     *     that actually appears in the DOM,
     *   - some container must hold ≥2 of those descendant children as a flat run,
     *     segmented at the repeating boundary child (each card starts a new W).
     *
     * No text is added or invented: existing elements are only re-parented. When
     * nothing qualifies the markup is returned unchanged.
     */
    private static function reconstructStrippedWrappers(string $markup, string $css): string
    {
        if (\trim($markup) === '') {
            return $markup;
        }

        $contracts = self::parseSelectorContracts($css);
        $descendantOf = $contracts['descendantOf'];
        $selfStyled = $contracts['selfStyled'];
        if ($descendantOf === []) {
            return $markup;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = \libxml_use_internal_errors(true);
        $loaded = @$dom->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__pbw_root__">' . $markup . '</div>',
            \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD,
        );
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return $markup;
        }

        $root = $dom->getElementById('__pbw_root__') ?? $dom->documentElement;
        if (!$root instanceof \DOMElement) {
            return $markup;
        }

        $domClasses = [];
        self::collectClasses($root, $domClasses);

        $changed = false;
        foreach ($descendantOf as $wrapper => $childSet) {
            if (!isset($selfStyled[$wrapper]) || isset($domClasses[$wrapper])) {
                continue;
            }
            $childrenPresent = [];
            foreach (\array_keys($childSet) as $child) {
                if (isset($domClasses[$child])) {
                    $childrenPresent[$child] = true;
                }
            }
            if ($childrenPresent === []) {
                continue;
            }

            $container = self::findWrapContainer($root, $childrenPresent);
            if ($container !== null && self::groupContainerChildren($dom, $container, $childrenPresent, $wrapper)) {
                $changed = true;
            }
        }

        if (!$changed) {
            return $markup;
        }

        $out = '';
        foreach (\iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        return \trim($out);
    }

    /**
     * Parse the stylesheet into a wrapper/descendant contract.
     *
     * @return array{selfStyled: array<string, true>, descendantOf: array<string, array<string, true>>}
     */
    private static function parseSelectorContracts(string $css): array
    {
        $css = (string) \preg_replace('!/\*.*?\*/!s', '', $css);
        $selfStyled = [];
        $descendantOf = [];
        $len = \strlen($css);
        $pos = 0;

        while ($pos < $len) {
            $brace = \strpos($css, '{', $pos);
            if ($brace === false) {
                break;
            }
            $semi = \strpos($css, ';', $pos);
            if ($semi !== false && $semi < $brace) {
                $pos = $semi + 1; // at-statement with no block (e.g. @import …;)
                continue;
            }

            $prelude = \trim(\substr($css, $pos, $brace - $pos));
            $blockEnd = self::matchBrace($css, $brace);
            $body = \substr($css, $brace + 1, $blockEnd - $brace - 1);
            $pos = $blockEnd + 1;

            if ($prelude === '') {
                continue;
            }
            if ($prelude[0] === '@') {
                $at = \strtolower(\strtok($prelude, " \t("));
                if ($at === '@media' || $at === '@supports') {
                    $inner = self::parseSelectorContracts($body);
                    foreach ($inner['selfStyled'] as $cls => $_) {
                        $selfStyled[$cls] = true;
                    }
                    foreach ($inner['descendantOf'] as $anc => $set) {
                        foreach ($set as $desc => $__) {
                            $descendantOf[$anc][$desc] = true;
                        }
                    }
                }
                continue;
            }

            foreach (\explode(',', $prelude) as $selector) {
                $selector = \trim($selector);
                if ($selector === '') {
                    continue;
                }
                // Treat every combinator (>, +, ~) as a descendant break so each
                // compound's primary class is isolated.
                $normalized = (string) \preg_replace('/\s*[>+~]\s*/', ' ', $selector);
                $split = \preg_split('/\s+/', \trim($normalized));
                $compounds = $split === false ? [] : $split;

                $classes = [];
                foreach ($compounds as $compound) {
                    $classes[] = self::primaryClass($compound);
                }

                $last = \end($classes);
                if (\is_string($last) && $last !== '') {
                    $selfStyled[$last] = true;
                }

                $count = \count($classes);
                for ($i = 0; $i < $count; $i++) {
                    $ancestor = $classes[$i];
                    if (!\is_string($ancestor) || $ancestor === '') {
                        continue;
                    }
                    for ($j = $i + 1; $j < $count; $j++) {
                        $descendant = $classes[$j];
                        if (\is_string($descendant) && $descendant !== '') {
                            $descendantOf[$ancestor][$descendant] = true;
                        }
                    }
                }
            }
        }

        return ['selfStyled' => $selfStyled, 'descendantOf' => $descendantOf];
    }

    /** First class token of a compound selector (e.g. `.card:hover` -> `card`), or null. */
    private static function primaryClass(string $compound): ?string
    {
        if (\preg_match('/\.([A-Za-z_][A-Za-z0-9_-]*)/', $compound, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Record every class attribute token present anywhere under $element.
     *
     * @param array<string, true> $into
     */
    private static function collectClasses(\DOMElement $element, array &$into): void
    {
        foreach (\iterator_to_array($element->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $class = \trim($child->getAttribute('class'));
            if ($class !== '') {
                $tokens = \preg_split('/\s+/', $class);
                foreach ($tokens === false ? [] : $tokens as $token) {
                    if ($token !== '') {
                        $into[$token] = true;
                    }
                }
            }
            self::collectClasses($child, $into);
        }
    }

    /**
     * Find the element holding the largest flat run (>= 2) of direct children
     * whose primary class is one of the wrapper's descendant classes.
     *
     * @param array<string, true> $childrenPresent
     */
    private static function findWrapContainer(\DOMElement $root, array $childrenPresent): ?\DOMElement
    {
        $best = null;
        $bestCount = 1;

        $stack = [$root];
        while ($stack !== []) {
            $element = \array_pop($stack);
            $count = 0;
            foreach ($element->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                $stack[] = $child;
                $primary = self::elementPrimaryClass($child);
                if ($primary !== null && isset($childrenPresent[$primary])) {
                    $count++;
                }
            }
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $element;
            }
        }

        return $best;
    }

    /**
     * Re-group a container's flat children into reconstructed wrapper elements,
     * segmenting at each occurrence of the repeating boundary child. Returns true
     * if the container was rewritten.
     *
     * @param array<string, true> $childrenPresent
     */
    private static function groupContainerChildren(
        \DOMDocument $dom,
        \DOMElement $container,
        array $childrenPresent,
        string $wrapper,
    ): bool {
        $kids = [];
        foreach ($container->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $kids[] = $node;
            }
        }
        if (\count($kids) < 2) {
            return false;
        }

        $boundary = null;
        foreach ($kids as $kid) {
            $primary = self::elementPrimaryClass($kid);
            if ($primary !== null && isset($childrenPresent[$primary])) {
                $boundary = $primary;
                break;
            }
        }
        if ($boundary === null) {
            return false;
        }

        $boundaryCount = 0;
        foreach ($kids as $kid) {
            if (self::elementPrimaryClass($kid) === $boundary) {
                $boundaryCount++;
            }
        }
        if ($boundaryCount < 2) {
            return false;
        }

        $leading = [];
        $groups = [];
        $current = null;
        foreach ($kids as $kid) {
            if (self::elementPrimaryClass($kid) === $boundary) {
                if ($current !== null) {
                    $groups[] = $current;
                }
                $current = [];
            }
            if ($current === null) {
                $leading[] = $kid;
            } else {
                $current[] = $kid;
            }
        }
        if ($current !== null) {
            $groups[] = $current;
        }
        if (\count($groups) < 2) {
            return false;
        }

        // Detach all existing children (elements and stray whitespace) so the
        // container can be rebuilt as leading-content + wrapped groups.
        foreach (\iterator_to_array($container->childNodes) as $node) {
            $container->removeChild($node);
        }
        foreach ($leading as $kid) {
            $container->appendChild($kid);
        }
        foreach ($groups as $group) {
            $box = $dom->createElement('div');
            $box->setAttribute('class', $wrapper);
            foreach ($group as $kid) {
                $box->appendChild($kid);
            }
            $container->appendChild($box);
        }

        return true;
    }

    /** First class token of an element's class attribute, or null. */
    private static function elementPrimaryClass(\DOMElement $element): ?string
    {
        $class = \trim($element->getAttribute('class'));
        if ($class === '') {
            return null;
        }
        $tokens = \preg_split('/\s+/', $class);
        if ($tokens === false || $tokens[0] === '') {
            return null;
        }

        return $tokens[0];
    }
}
