<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Decoder;

use Waaseyaa\PageBuilder\Block;
use Waaseyaa\PageBuilder\Contract\PageBuilderDecoderInterface;
use Waaseyaa\PageBuilder\DecodedPage;
use Waaseyaa\PageBuilder\DecodeRequest;
use Waaseyaa\PageBuilder\Html\HtmlCleaner;
use Waaseyaa\PageBuilder\Html\HtmlComponent;

/**
 * Decoder for pages authored as raw HTML carrying their own scoped `<style>`
 * block (common in migrated WordPress content pasted into an HTML widget).
 *
 * Converts the body into a clean component (sanitized markup + owned, scoped
 * CSS) via {@see HtmlComponent}, so the original cards/grids/portal survive as
 * real structure instead of flattening to text. Falls back to a clean text
 * block when there is no usable component.
 *
 * Lower priority than the concrete builders so a real Elementor/Gutenberg page
 * is handled by its own decoder first; this catches the "just pasted HTML+CSS"
 * case before the plain-HTML fallback.
 *
 * @api
 */
final class ScopedHtmlComponentDecoder implements PageBuilderDecoderInterface
{
    public function __construct(private readonly HtmlCleaner $cleaner = new HtmlCleaner()) {}

    public function id(): string
    {
        return 'scoped_html_component';
    }

    public function priority(): int
    {
        return 25;
    }

    public function supports(DecodeRequest $request): bool
    {
        return \stripos($request->body, '<style') !== false;
    }

    public function decode(DecodeRequest $request): DecodedPage
    {
        $component = HtmlComponent::fromHtml($request->body);
        if ($component === null) {
            $html = $this->cleaner->clean($request->body);

            return new DecodedPage('scoped_html_component', $html, $html === '' ? [] : [new Block(Block::TEXT, [], $html)]);
        }

        $block = new Block(Block::COMPONENT, ['scope' => $component['scope'], 'css' => $component['css']], $component['html']);

        return new DecodedPage('scoped_html_component', $component['html'], [$block]);
    }
}
