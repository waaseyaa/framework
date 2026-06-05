<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Contract;

use Waaseyaa\PageBuilder\DecodedPage;
use Waaseyaa\PageBuilder\DecodeRequest;

/**
 * Contract every page-builder decoder implements.
 *
 * A decoder owns the format knowledge for exactly one page builder. It declares
 * which builder it handles via {@see supports()} (detection from the body and/or
 * metadata such as postmeta or block markers) and converts a raw page body into
 * a normalized {@see DecodedPage} via {@see decode()}.
 *
 * This is the framework's builder-agnostic substrate: importers (the migration
 * platform), ingestion adapters, and any other consumer hand a
 * {@see DecodeRequest} to a {@see \Waaseyaa\PageBuilder\PageBuilderRegistry},
 * which selects the first decoder that {@see supports()} the request. New page
 * builders are supported by shipping a new decoder against this contract, never
 * by editing consumers.
 *
 * Decoders MUST be pure: identical requests yield identical output.
 *
 * @api
 */
interface PageBuilderDecoderInterface
{
    /**
     * Stable decoder id (snake_case, e.g. `elementor`, `gutenberg`,
     * `plain_html`). Recorded on {@see DecodedPage::$builder}.
     */
    public function id(): string;

    /**
     * Selection priority. Higher runs first. Concrete-builder decoders use a
     * positive priority; the plain-HTML fallback uses a low/negative priority so
     * it is consulted last.
     */
    public function priority(): int;

    /**
     * True when this decoder recognises the request as its builder.
     *
     * Detection only: cheap inspection of {@see DecodeRequest::$body} and
     * {@see DecodeRequest::$meta}. MUST NOT mutate state.
     */
    public function supports(DecodeRequest $request): bool;

    /**
     * Decode the request into clean semantic HTML plus a structured block list.
     *
     * Output MUST be free of builder leakage: no builder wrapper markup, no
     * builder CSS classes, no `<script>`, no raw shortcodes.
     */
    public function decode(DecodeRequest $request): DecodedPage;
}
