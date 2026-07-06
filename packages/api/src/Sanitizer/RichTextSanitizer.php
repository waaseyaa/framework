<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Sanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes HTML-bearing "richtext" field values at the read/serialization
 * boundary (R13 WP2, audit A11, SECURITY).
 *
 * text_long is the only field type whose value is rendered as HTML by a
 * server-side consumer: {@see \Waaseyaa\Api\Schema\SchemaPresenter}'s
 * WIDGET_MAP maps `text_long` to the `richtext` widget, and the admin SPA
 * (`packages/admin/app/components/schema/SchemaView.vue`) renders any field
 * with `x-widget === 'richtext'` via `v-html`. Every other field type
 * (`string`, `text`, etc.) is rendered as literal text, never interpreted as
 * markup, so sanitizing them would corrupt legitimate content (e.g. a
 * literal `<` in a title) without closing any real sink.
 *
 * Uses the SAME symfony/html-sanitizer `allowSafeElements()` config as
 * {@see \Waaseyaa\SSR\Formatter\HtmlFormatter} (the SSR read path), so the
 * server-side API/GraphQL/admin surfaces match the posture SSR already has:
 * a fixed allowlist of "safe" HTML elements/attributes (headings,
 * paragraphs, lists, links, emphasis, tables, etc.) with scripts, inline
 * event handlers, and non-safe elements stripped, and non-https URLs
 * upgraded/rejected via `forceHttpsUrls()`. See the class doc on
 * `HtmlSanitizerConfig::allowSafeElements()` (symfony/html-sanitizer) for
 * the exact element/attribute list.
 *
 * NON-LOSSY BY DESIGN: this class is applied only at read/serialization time
 * (ResourceSerializer::castAttributes, the GraphQL plain-field resolver,
 * FieldAutoSaveController's echoed response). The value AS STORED in the
 * entity/database is never touched -- an author's raw input is preserved
 * byte-for-byte at rest. This means: (1) a future consumer that needs the
 * raw markup (e.g. an editor re-opening the field for editing) still gets
 * the author's exact original bytes: (2) sanitizer allowlist changes take
 * effect retroactively on every existing row without a migration, since
 * nothing was mutated at write time; (3) legitimate but non-"safe" markup an
 * author intentionally stored (e.g. an <iframe> embed) will render stripped
 * wherever it is served through a sanitizing boundary -- this is a product
 * trade-off flagged for review, not an oversight.
 *
 * @api
 */
final class RichTextSanitizer
{
    /**
     * Field types whose value is HTML markup that MUST be sanitized before
     * leaving the server. Currently only `text_long` (the "richtext" widget
     * type, see class doc). Plain-text types (`string`, `text`, `email`,
     * etc.) are intentionally excluded.
     *
     * @var list<string>
     */
    public const HTML_FIELD_TYPES = ['text_long'];

    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = new HtmlSanitizerConfig()
            ->allowSafeElements()
            ->forceHttpsUrls();
        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * True when a field type's value must be run through {@see sanitize()}
     * before being served to a consumer.
     */
    public static function isHtmlFieldType(string $fieldType): bool
    {
        return \in_array($fieldType, self::HTML_FIELD_TYPES, true);
    }

    /**
     * Sanitize a single HTML string. Non-lossy for legitimate markup and
     * non-ASCII (e.g. Indigenous-language) text content; strips
     * script/event-handler/unsafe-element markup per `allowSafeElements()`.
     */
    public function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }

        return $this->sanitizer->sanitize($html);
    }

    /**
     * Sanitize a field value of unknown shape -- a plain string, null, or an
     * array (multi-value field) whose leaves may themselves be strings.
     * Non-string/non-array leaves (already-cast scalars) pass through
     * unchanged.
     */
    public function sanitizeValue(mixed $value): mixed
    {
        if (\is_string($value)) {
            return $this->sanitize($value);
        }

        if (\is_array($value)) {
            return array_map(fn(mixed $item): mixed => $this->sanitizeValue($item), $value);
        }

        return $value;
    }
}
