<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Decoder;

use Waaseyaa\PageBuilder\Block;
use Waaseyaa\PageBuilder\Contract\PageBuilderDecoderInterface;
use Waaseyaa\PageBuilder\DecodedPage;
use Waaseyaa\PageBuilder\DecodeRequest;
use Waaseyaa\PageBuilder\Html\HtmlCleaner;

/**
 * Decoder for the WordPress block editor (Gutenberg).
 *
 * Gutenberg serialises a page as HTML interleaved with block-delimiter comments:
 *
 *   <!-- wp:heading {"level":2} --><h2>Title</h2><!-- /wp:heading -->
 *   <!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->
 *   <!-- wp:image {"id":7} --><figure>...</figure><!-- /wp:image -->
 *
 * Detection is the presence of `<!-- wp:` markers in the body. Decoding strips
 * the delimiter comments, cleans the remaining HTML for the page output, and
 * walks the top-level blocks to produce the structured block list (mapping core
 * block names onto the normalized vocabulary).
 *
 * This is a focused decoder that proves the contract is genuinely builder-
 * agnostic: it shares nothing with the Elementor decoder beyond the interface
 * and the HTML cleaner. It covers the common core blocks; unknown blocks fall
 * back to their inner HTML so no content is dropped.
 *
 * @api
 */
final class GutenbergDecoder implements PageBuilderDecoderInterface
{
    public function __construct(private readonly HtmlCleaner $cleaner = new HtmlCleaner()) {}

    public function id(): string
    {
        return 'gutenberg';
    }

    public function priority(): int
    {
        return 50;
    }

    public function supports(DecodeRequest $request): bool
    {
        return \str_contains($request->body, '<!-- wp:');
    }

    public function decode(DecodeRequest $request): DecodedPage
    {
        $blocks = $this->parseBlocks($request->body);

        // Page HTML: strip the block-delimiter comments and clean what remains.
        $stripped = \preg_replace('/<!--\s*\/?wp:[^>]*-->/', '', $request->body) ?? $request->body;
        $html = $this->cleaner->clean($stripped);

        return new DecodedPage('gutenberg', $html, $blocks);
    }

    /**
     * @return list<Block>
     */
    private function parseBlocks(string $body): array
    {
        // Match paired blocks `<!-- wp:NAME ... -->INNER<!-- /wp:NAME -->` and
        // void blocks `<!-- wp:NAME ... /-->`.
        $pattern = '/<!--\s*wp:([a-z0-9_\/-]+)(\s+\{.*?\})?\s*(\/)?-->'
            . '(?:(.*?)<!--\s*\/wp:\1\s*-->)?/s';

        if (\preg_match_all($pattern, $body, $matches, \PREG_SET_ORDER) === 0) {
            return [];
        }

        $blocks = [];
        foreach ($matches as $m) {
            $name = $m[1];
            $inner = $m[4] ?? '';
            $block = $this->mapBlock($name, $inner);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    private function mapBlock(string $name, string $innerHtml): ?Block
    {
        // Normalise `core/paragraph` and `paragraph` to the same token.
        $short = \str_contains($name, '/') ? \substr($name, \strrpos($name, '/') + 1) : $name;
        $cleanInner = $this->cleaner->clean($innerHtml);

        if ($cleanInner === '' && $short !== 'separator') {
            return null;
        }

        return match ($short) {
            'heading' => new Block(
                Block::HEADING,
                ['level' => $this->headingLevel($cleanInner), 'text' => \trim(\strip_tags($cleanInner))],
                $cleanInner,
            ),
            'paragraph' => new Block(Block::TEXT, ['html' => $cleanInner], $cleanInner),
            'list' => new Block(Block::LIST, ['html' => $cleanInner], $cleanInner),
            'image', 'cover' => new Block(Block::IMAGE, ['html' => $cleanInner], $cleanInner),
            'quote', 'pullquote' => new Block(Block::TEXT, ['html' => $cleanInner], $cleanInner),
            'separator' => null,
            default => new Block(Block::HTML, ['html' => $cleanInner], $cleanInner),
        };
    }

    private function headingLevel(string $html): int
    {
        if (\preg_match('/<h([1-6])\b/i', $html, $m) === 1) {
            return (int) $m[1];
        }

        return 2;
    }
}
