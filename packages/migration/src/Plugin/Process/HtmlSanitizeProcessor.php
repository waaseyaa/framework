<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Plugin\Process;

use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;
use Waaseyaa\Migration\Plugin\ReservedPluginIds;

/**
 * Sanitize an HTML string by stripping any tag or attribute that is not on
 * the configured allowlist.
 *
 * Used for rich-text bodies migrated from WordPress, Drupal, or other CMS
 * sources where the markup pedigree is unknown. The default allowlist covers
 * common safe semantic tags and standard hyperlink / image attributes.
 *
 * Implementation strategy:
 *
 *   - If the optional dependency `ezyang/htmlpurifier` is present in vendor,
 *     prefer it — it normalises malformed markup and strips dangerous CSS /
 *     URIs more aggressively than DOMDocument can.
 *   - Otherwise fall back to a DOMDocument-based allowlist filter. This path
 *     is correct for well-formed inputs and tolerant of malformed ones
 *     (libxml errors are suppressed; the loader continues on the best-effort
 *     parse). Allowed URL attributes (`href`, `src`, …) are scheme-checked:
 *     only `http`/`https`/`mailto`/`tel`/`ftp` and scheme-less (relative) URLs
 *     survive — `javascript:`, `data:`, `vbscript:`, etc. are stripped, so the
 *     allowlist cannot pass a dangerous URI value through under a safe name.
 *
 * Both paths preserve `<a href="…">` and `<img src="…">` round-trips when the
 * default allowlists are in effect.
 *
 * @api
 *
 * @spec FR-010 — framework-reserved process plugin (`html_sanitize`)
 */
final readonly class HtmlSanitizeProcessor implements ProcessPluginInterface
{
    /**
     * Tags retained by default.
     *
     * @var list<string>
     */
    public const array DEFAULT_ALLOWED_TAGS = [
        'p', 'a', 'br', 'em', 'strong', 'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'code', 'pre', 'img',
    ];

    /**
     * Attributes retained by default, keyed by tag name.
     *
     * @var array<string, list<string>>
     */
    public const array DEFAULT_ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title'],
    ];

    /**
     * Tags whose content libxml parses under a raw-text / CDATA content model
     * (`\DOMCdataSection`, serialized VERBATIM by `saveHTML()` — no HTML-entity
     * escaping). Because escaping is decided by a node's PARENT content model,
     * a payload kept inside one of these elements round-trips as LIVE markup
     * (`<script>`, `onerror=`, `javascript:`) even after tag/attribute
     * filtering — a stored-XSS bypass, no nesting required (B-4 class). They are
     * therefore force-stripped even when a custom allowlist names them, so their
     * content is hoisted into a normal-content context and escaped (or dropped,
     * for script/style). RCDATA tags (`title`, `textarea`) are deliberately NOT
     * here: libxml parses them as `\DOMText` and `saveHTML()` escapes them.
     *
     * @var list<string>
     */
    private const RAW_TEXT_CONTENT_MODEL_TAGS = ['script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'];

    /**
     * Attribute names whose value is a URL and must be scheme-checked. An allowed attribute
     * here is stripped when its value resolves to a non-safe scheme — the name allowlist alone
     * does not catch `<a href="javascript:…">` / `<img src="data:text/html,…">` (B-4).
     *
     * @var list<string>
     */
    private const URL_ATTRIBUTES = ['href', 'src', 'poster', 'cite', 'action', 'formaction', 'background', 'longdesc', 'data'];

    /**
     * URL schemes permitted on URL-bearing attributes. Anything else (`javascript`, `data`,
     * `vbscript`, `file`, …) is rejected; scheme-less URLs (relative, fragment, protocol-relative)
     * are permitted.
     *
     * @var list<string>
     */
    private const SAFE_URL_SCHEMES = ['http', 'https', 'mailto', 'tel', 'ftp'];

    /**
     * @param string $sourceField Source-record field whose HTML payload is sanitized. Non-empty.
     * @param list<string> $allowedTags Tag names retained on the output.
     * @param array<string, list<string>> $allowedAttributes Tag => list of allowed attribute names.
     *
     * @throws \InvalidArgumentException If $sourceField is empty.
     */
    public function __construct(
        public string $sourceField,
        public array $allowedTags = self::DEFAULT_ALLOWED_TAGS,
        public array $allowedAttributes = self::DEFAULT_ALLOWED_ATTRIBUTES,
    ) {
        if ($sourceField === '') {
            throw new \InvalidArgumentException('HtmlSanitizeProcessor::$sourceField must be a non-empty string.');
        }
    }

    public function id(): string
    {
        return ReservedPluginIds::HTML_SANITIZE;
    }

    public function stability(): string
    {
        return 'stable';
    }

    public function transform(mixed $value, ProcessContext $context): mixed
    {
        // PassThrough-style head behaviour: read from the source record if the
        // upstream value is null (chain head). Otherwise sanitize the chained
        // value — `$value` may have been transformed by an earlier plugin.
        $input = $value ?? $context->sourceRecord->field($this->sourceField, null);

        if ($input === null) {
            return null;
        }

        if (!is_string($input)) {
            // Non-string inputs are not HTML; cast and run through to keep the
            // pipeline strict but lenient.
            $input = (string) $input;
        }

        if ($input === '') {
            return '';
        }

        // HTMLPurifier is an optional dependency — reference by string FQCN so
        // PHPStan does not require the symbol to be installed in vendor.
        if (class_exists('HTMLPurifier') && class_exists('HTMLPurifier_Config')) {
            $purified = $this->purify($input);
            if (is_string($purified)) {
                return $purified;
            }
        }

        return $this->stripWithDom($input);
    }

    /**
     * Invoke HTMLPurifier via string-FQCN reflection so the optional vendor
     * dependency does not become a hard build-time requirement.
     *
     * Returns null only when reflection fails — the caller falls back to the
     * DOMDocument allowlist path in that case.
     */
    private function purify(string $html): ?string
    {
        // Names are computed at call time so PHPStan does not try to resolve
        // them to (absent) class symbols.
        $configFqcn = self::deferredFqcn('HTMLPurifier_Config');
        $purifierFqcn = self::deferredFqcn('HTMLPurifier');

        try {
            $configReflection = new \ReflectionClass($configFqcn);
            $createDefault = $configReflection->getMethod('createDefault');
            $config = $createDefault->invoke(null);
            if (!is_object($config)) {
                return null;
            }
            $config->set('HTML.Allowed', $this->buildPurifierAllowed());
            $config->set('Cache.DefinitionImpl', null);

            $purifierReflection = new \ReflectionClass($purifierFqcn);
            $purifier = $purifierReflection->newInstance($config);

            $result = $purifier->purify($html);
        } catch (\ReflectionException) {
            return null;
        }

        return is_string($result) ? $result : null;
    }

    /**
     * Identity helper: returns the input string. Exists to hide a literal
     * class name from PHPStan's class-string narrowing, since the named
     * class is an optional vendor dependency that may not be installed.
     */
    private static function deferredFqcn(string $name): string
    {
        return $name;
    }

    private function buildPurifierAllowed(): string
    {
        $parts = [];
        foreach ($this->allowedTags as $tag) {
            $attrs = $this->allowedAttributes[$tag] ?? [];
            if ($attrs === []) {
                $parts[] = $tag;
                continue;
            }
            $parts[] = $tag . '[' . implode('|', $attrs) . ']';
        }

        return implode(',', $parts);
    }

    private function stripWithDom(string $html): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        // Wrap in a UTF-8 charset declaration so DOMDocument doesn't mangle
        // multibyte characters under its default ISO-8859-1 assumption.
        $loadedOk = @$dom->loadHTML(
            '<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>',
            \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loadedOk === false) {
            // The loader failed completely. Strip every tag as a safe fallback.
            return strip_tags($html);
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return strip_tags($html);
        }

        $this->filterNode($body);

        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    private function filterNode(\DOMElement $element): void
    {
        // Walk children in reverse so we can remove nodes safely.
        $children = [];
        foreach ($element->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                $this->applyChildPolicy($child);
            } elseif ($child instanceof \DOMCdataSection) {
                // Belt-and-suspenders: an ALLOWED CDATA-content-model tag (only
                // reachable via a custom allowlist that includes xmp / iframe /
                // noembed / noframes / plaintext) parses its inner payload as a
                // raw CDATA section. Left alone it would serialize as live
                // markup — neutralize it here too, not only on the hoist path.
                $this->convertCdataToText($child);
            }
        }
    }

    /**
     * Apply the allowlist policy to a single element: strip it (hoisting its
     * children up as text/markup) if its tag is disallowed, otherwise filter
     * its attributes and recurse into its own children.
     *
     * This is also the re-entry point used when {@see replaceWithTextContent}
     * promotes a disallowed element's descendants up to a new parent. Without
     * re-running this policy on every promoted node, a payload nested inside
     * a disallowed wrapper (e.g. `<span><img onerror=…></span>`) would be
     * hoisted verbatim and skip both the tag allowlist and attribute/URL
     * scheme filtering — a stored-XSS bypass (B-4 reopened at nested depth).
     * Re-applying the policy at every promotion depth guarantees a fixpoint:
     * no disallowed tag or attribute can survive regardless of how deeply it
     * was originally nested.
     */
    private function applyChildPolicy(\DOMElement $child): void
    {
        $tag = strtolower($child->tagName);
        if (!in_array($tag, $this->allowedTags, true)
            || in_array($tag, self::RAW_TEXT_CONTENT_MODEL_TAGS, true)) {
            // Replace the disallowed element with its text content.
            //
            // A raw-text-content-model tag is force-stripped even when a custom
            // allowlist names it: libxml parses its payload as a raw CDATA
            // section, and `saveHTML()` escapes a node according to its PARENT's
            // content model — so a text node kept INSIDE such an element still
            // serializes verbatim (unescaped) and could smuggle live markup.
            // Stripping the wrapper routes the payload through the hoist path in
            // replaceWithTextContent(), where it lands in a normal-content
            // parent and is properly entity-escaped (or, for script/style,
            // dropped wholesale). This is why they can never be safely kept.
            $this->replaceWithTextContent($child);
            return;
        }

        $this->filterAttributes($child, $tag);
        $this->filterNode($child);
    }

    /**
     * Replace a CDATA section with an escaped text node carrying the same data,
     * in place under its current parent.
     *
     * libxml2's HTML parser resolves the *content model* of a handful of tags —
     * `script`, `style`, and the CDATA-content-model group `xmp` / `iframe` /
     * `noembed` / `noframes` / `plaintext` — by parsing their inner payload as a
     * raw `\DOMCdataSection` (nodeType 4), NOT a `\DOMText` node.
     * `\DOMDocument::saveHTML()` serializes a CDATA section VERBATIM (no
     * HTML-entity escaping, unlike a text node), so a `<script>` / `onerror=` /
     * `javascript:` payload wrapped in one of those tags round-trips as LIVE
     * markup even though it was never a real DOM element the tag/attribute
     * allowlist could see — a stored-XSS bypass, no nesting required (B-4 class).
     * (Contrast RCDATA tags `title` / `textarea`, which libxml parses as
     * `\DOMText` and saveHTML escapes correctly — those are already safe.)
     * Converting to a text node makes saveHTML entity-escape the payload
     * (`<script>` → `&lt;script&gt;`), neutralizing it while preserving the
     * author's visible text — including multibyte / Indigenous orthography.
     */
    private function convertCdataToText(\DOMCdataSection $cdata): void
    {
        $document = $cdata->ownerDocument;
        $parent = $cdata->parentNode;
        if ($document === null || $parent === null) {
            return;
        }

        $parent->replaceChild($document->createTextNode($cdata->data), $cdata);
    }

    private function filterAttributes(\DOMElement $element, string $tag): void
    {
        $allowed = $this->allowedAttributes[$tag] ?? [];

        // Snapshot attribute names — DOMNamedNodeMap is live and removeAttribute()
        // during iteration desynchronises the cursor.
        $names = [];
        foreach ($element->attributes as $attr) {
            // `$attr` is typed `DOMAttr` by DOMNamedNodeMap's iterator stub.
            $names[] = $attr->name;
        }

        foreach ($names as $name) {
            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($name);
                continue;
            }

            // The attribute is allowed, but an allowed URL attribute can still carry a
            // dangerous value: `<a href="javascript:…">` / `<img src="data:text/html,…">`
            // both keep the attribute name. Strip the attribute when its URL scheme is not
            // on the safe list (B-4 — stored XSS in migrated content).
            if (in_array(strtolower($name), self::URL_ATTRIBUTES, true)
                && $this->hasUnsafeUrlScheme($element->getAttribute($name))) {
                $element->removeAttribute($name);
            }
        }
    }

    /**
     * Whether a URL attribute value carries a scheme that is not on the safe allowlist.
     *
     * Whitespace and control characters are stripped before the scheme is read because
     * browsers ignore them when resolving it (e.g. "java\tscript:" still runs as javascript),
     * and the comparison is case-insensitive. Scheme-less values (relative paths, fragments,
     * "//host") have no scheme and are treated as safe.
     */
    private function hasUnsafeUrlScheme(string $value): bool
    {
        $normalized = strtolower((string) preg_replace('/[\s\x00-\x1F\x7F]+/', '', $value));

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $normalized, $matches) !== 1) {
            return false;
        }

        return !in_array($matches[1], self::SAFE_URL_SCHEMES, true);
    }

    private function replaceWithTextContent(\DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        $document = $element->ownerDocument;
        if ($document === null) {
            $parent->removeChild($element);
            return;
        }

        // Move children up to the parent in original order, then drop the
        // disallowed wrapper. This preserves the text payload of e.g.
        // <script>alert('x')</script> → no element (text is preserved by the
        // DOM tree only if it had text children; <script> with raw JS leaves
        // a text node we want to drop). To strip script-style payloads cleanly
        // we DON'T move text children of explicit script/style — replace with
        // empty string instead.
        $tagLower = strtolower($element->tagName);
        if ($tagLower === 'script' || $tagLower === 'style') {
            $parent->removeChild($element);
            return;
        }

        while ($element->firstChild !== null) {
            $child = $element->firstChild;

            if ($child instanceof \DOMCdataSection) {
                // The wrapper being stripped is a CDATA-content-model tag (xmp /
                // iframe / noembed / noframes / plaintext): its raw payload is a
                // CDATA section that saveHTML would emit VERBATIM as live markup.
                // Neutralize it IN PLACE first — replaceChild swaps it for an
                // escaped text node, which becomes the new firstChild and is
                // hoisted (as harmless escaped text) on the next iteration.
                $this->convertCdataToText($child);
                continue;
            }

            $parent->insertBefore($child, $element);

            // The promoted node has skipped filterNode()'s per-child snapshot
            // loop entirely (it was never a child of $parent when that loop
            // ran) — without this, a disallowed/dangerous element nested
            // inside a stripped wrapper would survive verbatim, tag and
            // attributes unchecked, at its new (shallower) position. Re-run
            // the same policy on it now, in its new position, so nesting
            // depth cannot be used to smuggle a disallowed tag or an unsafe
            // attribute/URL scheme past the allowlist.
            if ($child instanceof \DOMElement) {
                $this->applyChildPolicy($child);
            }
        }

        $parent->removeChild($element);
    }
}
