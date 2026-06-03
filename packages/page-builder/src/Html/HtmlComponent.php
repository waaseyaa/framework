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
}
