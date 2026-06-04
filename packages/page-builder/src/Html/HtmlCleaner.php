<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Html;

/**
 * Produces clean semantic HTML with no page-builder leakage.
 *
 * Guarantees on its output:
 *   - no `<script>` / `<style>` / `<iframe>` / `<form>` (dropped with content),
 *   - no `class` / `id` / `style` / `on*` / `data-*` attributes (so builder CSS
 *     classes like `elementor-widget` never survive),
 *   - only an allowlisted set of semantic tags (others are unwrapped, keeping
 *     their text/children),
 *   - no raw shortcodes (`[gallery]`, `[contact-form-7 ...]`, `[/foo]`).
 *
 * DOM-based (ext-dom), dependency-free, deterministic. Tolerant of malformed
 * input (libxml errors are suppressed; best-effort parse).
 *
 * @api
 */
final class HtmlCleaner
{
    /** @var list<string> */
    private const array ALLOWED_TAGS = [
        'p', 'a', 'br', 'em', 'strong', 'b', 'i', 'u', 's',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'code', 'pre', 'img', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
        'hr', 'sub', 'sup', 'small',
    ];

    /** @var array<string, list<string>> */
    private const array ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
    ];

    /** Tags whose entire subtree is dropped. */
    private const array DROP_TAGS = ['script', 'style', 'iframe', 'form', 'noscript'];

    public function clean(string $html): string
    {
        $html = $this->stripShortcodes($html);
        if (\trim($html) === '') {
            return '';
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = \libxml_use_internal_errors(true);
        $loaded = @$dom->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__pb_root__">' . $html . '</div>',
            \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD,
        );
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return \trim(\strip_tags($html));
        }

        $root = $dom->getElementById('__pb_root__');
        if (!$root instanceof \DOMElement) {
            // getElementById can miss without a DTD; fall back to first child.
            $root = $dom->documentElement;
        }
        if (!$root instanceof \DOMElement) {
            return \trim(\strip_tags($html));
        }

        $this->filterNode($root);

        $out = '';
        foreach (\iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        // Collapse runs of whitespace introduced by unwrapping.
        $out = \preg_replace('/\s+\n/', "\n", $out) ?? $out;

        return \trim($out);
    }

    /**
     * Remove WordPress-style shortcodes: `[tag ...]`, `[/tag]`, `[tag]`.
     * Conservative: only matches tokens that look like shortcodes (start with a
     * letter, shortcode-ish name) so ordinary bracketed prose survives.
     */
    public function stripShortcodes(string $text): string
    {
        return \preg_replace('/\[\/?[a-zA-Z][a-zA-Z0-9_\-]*(?:[^\]]*)\]/', '', $text) ?? $text;
    }

    private function filterNode(\DOMElement $element): void
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

            if (!\in_array($tag, self::ALLOWED_TAGS, true)) {
                // Recurse first so descendants are cleaned, then unwrap.
                $this->filterNode($child);
                $this->unwrap($child);
                continue;
            }

            $this->scrubAttributes($child, $tag);
            $this->filterNode($child);
        }
    }

    private function scrubAttributes(\DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
        $names = [];
        foreach ($element->attributes as $attr) {
            $names[] = $attr->name;
        }
        foreach ($names as $name) {
            if (!\in_array($name, $allowed, true)) {
                $element->removeAttribute($name);
            }
        }
    }

    /**
     * Replace an element with its children, preserving order. Surrounds the
     * moved content with whitespace text nodes so that unwrapping inline
     * wrappers (e.g. `<span>9 documents</span><a>Open</a>`) does not concatenate
     * adjacent text into run-together words ("documentsOpen"). Redundant
     * whitespace collapses in HTML rendering, so this is always safe.
     */
    private function unwrap(\DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }
        $doc = $element->ownerDocument;
        if ($doc !== null) {
            $parent->insertBefore($doc->createTextNode(' '), $element);
        }
        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        if ($doc !== null) {
            $parent->insertBefore($doc->createTextNode(' '), $element);
        }
        $parent->removeChild($element);
    }
}
