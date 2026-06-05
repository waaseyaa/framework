<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Decoder;

use Waaseyaa\PageBuilder\Block;
use Waaseyaa\PageBuilder\Contract\PageBuilderDecoderInterface;
use Waaseyaa\PageBuilder\DecodedPage;
use Waaseyaa\PageBuilder\DecodeRequest;
use Waaseyaa\PageBuilder\Html\HtmlCleaner;

/**
 * Fallback decoder for plain / classic-editor content (no page builder).
 *
 * Supports every request at the lowest priority, so the registry always has a
 * decoder of last resort. It simply cleans the raw body into safe semantic
 * HTML.
 *
 * @api
 */
final class PlainHtmlDecoder implements PageBuilderDecoderInterface
{
    public function __construct(private readonly HtmlCleaner $cleaner = new HtmlCleaner()) {}

    public function id(): string
    {
        return 'plain_html';
    }

    public function priority(): int
    {
        return -100;
    }

    public function supports(DecodeRequest $request): bool
    {
        return true;
    }

    public function decode(DecodeRequest $request): DecodedPage
    {
        $html = $this->cleaner->clean($request->body);
        $blocks = $html === '' ? [] : [new Block(Block::HTML, ['html' => $html], $html)];

        return new DecodedPage('plain_html', $html, $blocks);
    }
}
