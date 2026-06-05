<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder;

use Waaseyaa\PageBuilder\Contract\PageBuilderDecoderInterface;
use Waaseyaa\PageBuilder\Decoder\ElementorDecoder;
use Waaseyaa\PageBuilder\Decoder\GutenbergDecoder;
use Waaseyaa\PageBuilder\Decoder\PlainHtmlDecoder;
use Waaseyaa\PageBuilder\Decoder\ScopedHtmlComponentDecoder;

/**
 * Selects a decoder for a {@see DecodeRequest} and runs it.
 *
 * Decoders are consulted in descending {@see PageBuilderDecoderInterface::priority()}
 * order; the first whose {@see PageBuilderDecoderInterface::supports()} returns
 * true wins. The plain-HTML fallback has the lowest priority and supports every
 * request, so {@see decode()} always returns a {@see DecodedPage}.
 *
 * This mirrors the framework's other detect-and-dispatch registries (migration
 * plugin registry, access policy registry): a small contract, many pluggable
 * implementations, one selection point. New builders are added by registering a
 * decoder, never by branching in consumers.
 *
 * @api
 */
final class PageBuilderRegistry
{
    /** @var list<PageBuilderDecoderInterface> */
    private array $decoders = [];

    /**
     * @param iterable<PageBuilderDecoderInterface> $decoders
     */
    public function __construct(iterable $decoders = [])
    {
        foreach ($decoders as $decoder) {
            $this->decoders[] = $decoder;
        }
        $this->sort();
    }

    /**
     * Registry pre-loaded with the framework's shipped decoders: Elementor,
     * Gutenberg, and the plain-HTML fallback.
     */
    public static function withDefaults(): self
    {
        return new self([
            new ElementorDecoder(),
            new GutenbergDecoder(),
            new ScopedHtmlComponentDecoder(),
            new PlainHtmlDecoder(),
        ]);
    }

    /**
     * Register an additional decoder (e.g. an app-contributed builder). Returns
     * $this for chaining.
     */
    public function addDecoder(PageBuilderDecoderInterface $decoder): self
    {
        $this->decoders[] = $decoder;
        $this->sort();

        return $this;
    }

    /** @return list<PageBuilderDecoderInterface> */
    public function decoders(): array
    {
        return $this->decoders;
    }

    /**
     * The decoder that will handle this request, or null if none supports it
     * (only possible when no fallback decoder is registered).
     */
    public function decoderFor(DecodeRequest $request): ?PageBuilderDecoderInterface
    {
        foreach ($this->decoders as $decoder) {
            if ($decoder->supports($request)) {
                return $decoder;
            }
        }

        return null;
    }

    /**
     * Decode a request. Throws only when no decoder (not even a fallback)
     * supports it, which a default registry never allows.
     *
     * @throws \RuntimeException When no registered decoder supports the request.
     */
    public function decode(DecodeRequest $request): DecodedPage
    {
        $decoder = $this->decoderFor($request);
        if ($decoder === null) {
            throw new \RuntimeException(
                'PageBuilderRegistry: no decoder supports the request (register a fallback decoder).',
            );
        }

        return $decoder->decode($request);
    }

    private function sort(): void
    {
        \usort(
            $this->decoders,
            static fn(PageBuilderDecoderInterface $a, PageBuilderDecoderInterface $b): int
                => $b->priority() <=> $a->priority(),
        );
    }
}
