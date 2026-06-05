<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder;

/**
 * Result of decoding a page body.
 *
 * `html` is clean semantic HTML (no builder wrapper markup, no builder CSS
 * classes, no `<script>`, no raw shortcodes). `blocks` is the structured block
 * list (may be empty for opaque passthrough decodes). `builder` records which
 * decoder produced this (e.g. `elementor`, `gutenberg`, `plain_html`) for
 * provenance and diagnostics.
 *
 * @api
 */
final readonly class DecodedPage
{
    /**
     * @param string $builder Id of the decoder that produced this page.
     * @param string $html Clean semantic HTML.
     * @param list<Block> $blocks Structured block list (possibly empty).
     */
    public function __construct(
        public string $builder,
        public string $html,
        public array $blocks = [],
    ) {}
}
