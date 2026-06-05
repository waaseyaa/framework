<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder;

/**
 * Input to a page-builder decode.
 *
 * Carries the raw page body plus an opaque metadata map (e.g. WordPress
 * postmeta) that decoders use both to DETECT their builder and to DECODE it.
 * Page builders store their authored content in different places: Elementor in
 * a `_elementor_data` postmeta JSON tree, Gutenberg as block-comment markers
 * inside the body itself, classic editors as plain HTML in the body. A single
 * request shape covers all of them.
 *
 * @api
 */
final readonly class DecodeRequest
{
    /**
     * @param string $body Raw page body (e.g. WordPress `post_content`). May be empty.
     * @param array<string, mixed> $meta Side-channel metadata keyed by name (e.g. postmeta: `_elementor_data`, `_wp_page_template`). May be empty.
     * @param array<string, scalar> $hints Optional caller hints (e.g. `['locale' => 'en']`). Decoders may ignore these.
     */
    public function __construct(
        public string $body = '',
        public array $meta = [],
        public array $hints = [],
    ) {}

    /**
     * Convenience accessor for a metadata value.
     */
    public function meta(string $key, mixed $default = null): mixed
    {
        return \array_key_exists($key, $this->meta) ? $this->meta[$key] : $default;
    }
}
