<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\PageBuilder\Block;
use Waaseyaa\PageBuilder\Decoder\GutenbergDecoder;
use Waaseyaa\PageBuilder\DecodeRequest;

#[CoversClass(GutenbergDecoder::class)]
final class GutenbergDecoderTest extends TestCase
{
    private const string BODY = <<<'HTML'
        <!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Welcome</h2><!-- /wp:heading -->
        <!-- wp:paragraph --><p>Hello from the <strong>block editor</strong>.</p><!-- /wp:paragraph -->
        <!-- wp:list --><ul><li>One</li><li>Two</li></ul><!-- /wp:list -->
        <!-- wp:image {"id":7} --><figure class="wp-block-image"><img src="/x.jpg" alt="x"/></figure><!-- /wp:image -->
        <!-- wp:html --><script>alert(1)</script><p>after</p><!-- /wp:html -->
        HTML;

    #[Test]
    public function it_detects_gutenberg_by_block_markers(): void
    {
        $decoder = new GutenbergDecoder();

        self::assertTrue($decoder->supports(new DecodeRequest(body: self::BODY)));
        self::assertFalse($decoder->supports(new DecodeRequest(body: '<p>plain</p>')));
    }

    #[Test]
    public function it_strips_block_comments_and_leakage(): void
    {
        $decoder = new GutenbergDecoder();
        $page = $decoder->decode(new DecodeRequest(body: self::BODY));

        self::assertSame('gutenberg', $page->builder);
        self::assertStringNotContainsString('<!-- wp:', $page->html, 'block comments removed');
        self::assertStringNotContainsStringIgnoringCase('wp-block', $page->html, 'no wp-block classes');
        self::assertStringNotContainsStringIgnoringCase('class=', $page->html);
        self::assertStringNotContainsStringIgnoringCase('<script', $page->html);
        self::assertStringContainsString('Welcome', $page->html);
        self::assertStringContainsString('block editor', $page->html);
        self::assertStringContainsString('after', $page->html);
    }

    #[Test]
    public function it_maps_core_blocks_to_normalized_types(): void
    {
        $decoder = new GutenbergDecoder();
        $page = $decoder->decode(new DecodeRequest(body: self::BODY));

        $types = \array_map(static fn(Block $b): string => $b->type, $page->blocks);
        self::assertContains(Block::HEADING, $types);
        self::assertContains(Block::TEXT, $types);
        self::assertContains(Block::LIST, $types);
        self::assertContains(Block::IMAGE, $types);

        $heading = $this->firstOfType($page->blocks, Block::HEADING);
        self::assertSame(2, $heading->data['level']);
        self::assertSame('Welcome', $heading->data['text']);
    }

    /** @param list<Block> $blocks */
    private function firstOfType(array $blocks, string $type): Block
    {
        foreach ($blocks as $b) {
            if ($b->type === $type) {
                return $b;
            }
        }
        self::fail("no block of type {$type}");
    }
}
