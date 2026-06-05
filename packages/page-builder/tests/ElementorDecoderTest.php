<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\PageBuilder\Block;
use Waaseyaa\PageBuilder\BlockRenderer;
use Waaseyaa\PageBuilder\Decoder\ElementorDecoder;
use Waaseyaa\PageBuilder\DecodeRequest;

#[CoversClass(ElementorDecoder::class)]
#[CoversClass(BlockRenderer::class)]
final class ElementorDecoderTest extends TestCase
{
    private function fixture(string $name): string
    {
        $path = __DIR__ . '/Fixtures/' . $name;
        self::assertFileExists($path, "Synthetic Elementor fixture {$name} must be committed.");

        return (string) \file_get_contents($path);
    }

    private function request(string $elementorData, string $body = '<p>plain fallback</p>'): DecodeRequest
    {
        return new DecodeRequest(body: $body, meta: ['_elementor_data' => $elementorData]);
    }

    /**
     * Flatten the block tree to a list for assertions.
     *
     * @param list<Block> $blocks
     * @return list<Block>
     */
    private function flatten(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            $out[] = $b;
            $out = \array_merge($out, $this->flatten($b->children));
        }

        return $out;
    }

    // ---- detection + fidelity (About fixture) -------------------------------

    #[Test]
    public function it_detects_elementor_only_when_postmeta_present(): void
    {
        $decoder = new ElementorDecoder();

        self::assertTrue($decoder->supports($this->request($this->fixture('elementor-about.json'))));
        self::assertFalse($decoder->supports(new DecodeRequest(body: '<p>hi</p>', meta: [])));
        self::assertFalse($decoder->supports(new DecodeRequest(body: '<p>hi</p>', meta: ['_elementor_data' => '[]'])));
    }

    #[Test]
    public function it_renders_about_page_content(): void
    {
        $page = (new ElementorDecoder())->decode($this->request($this->fixture('elementor-about.json')));

        self::assertSame('elementor', $page->builder);
        self::assertStringContainsString('Our Mission', $page->html);
        self::assertStringContainsString('Acme Community', $page->html);
        self::assertStringContainsString('12.34', $page->html);
    }

    #[Test]
    public function decoded_html_has_no_builder_leakage(): void
    {
        $html = (new ElementorDecoder())->decode($this->request($this->fixture('elementor-about.json')))->html;

        self::assertStringNotContainsStringIgnoringCase('elementor-', $html, 'no elementor builder classes');
        self::assertStringNotContainsStringIgnoringCase('wp-block', $html, 'no gutenberg builder classes');
        self::assertStringNotContainsStringIgnoringCase('class="elementor', $html);
        self::assertStringNotContainsStringIgnoringCase('data-elementor', $html);
        self::assertStringNotContainsStringIgnoringCase('<script', $html, 'no script tags');
        self::assertSame(0, \preg_match('/\[\/?[a-z][a-z0-9_\-]*(?:[^\]]*)\]/i', $html), 'no raw shortcodes');
        // Framework render classes are allowed and expected (pb-*), not builder classes.
        self::assertStringContainsString('pb-', $html);
    }

    #[Test]
    public function it_is_deterministic(): void
    {
        $a = (new ElementorDecoder())->decode($this->request($this->fixture('elementor-about.json')));
        $b = (new ElementorDecoder())->decode($this->request($this->fixture('elementor-about.json')));
        self::assertSame($a->html, $b->html);
    }

    #[Test]
    public function malformed_tree_falls_back_to_plain_body(): void
    {
        $page = (new ElementorDecoder())->decode(new DecodeRequest(
            body: '<p>Real body <strong>here</strong>.</p>',
            meta: ['_elementor_data' => 'not valid json {'],
        ));
        self::assertStringContainsString('Real body', $page->html);
    }

    // ---- structure preservation (Home fixture) ------------------------------

    #[Test]
    public function home_emits_a_structured_block_tree_not_a_flat_list(): void
    {
        $page = (new ElementorDecoder())->decode($this->request($this->fixture('elementor-home.json')));
        $all = $this->flatten($page->blocks);

        $types = \array_map(static fn(Block $b): string => $b->type, $all);
        self::assertContains(Block::BAND, $types, 'tree has structural bands');
        self::assertContains(Block::CARD, $types, 'tree has cards');
        self::assertContains(Block::HEADING, $types, 'tree has headings');

        // Top-level blocks are structural (bands), not a flat run of leaves.
        foreach ($page->blocks as $top) {
            self::assertContains($top->type, [Block::BAND, Block::CARD, Block::GRID], 'top-level blocks are structural');
        }
    }

    #[Test]
    public function home_services_is_a_card_grid_with_title_description_and_button(): void
    {
        $page = (new ElementorDecoder())->decode($this->request($this->fixture('elementor-home.json')));
        $all = $this->flatten($page->blocks);

        $cards = \array_values(\array_filter($all, static fn(Block $b): bool => $b->type === Block::CARD));
        self::assertGreaterThanOrEqual(6, \count($cards), 'the Services section is a grid of at least 6 cards');

        // Each service card carries an icon-box (title + description) and a button.
        $cardsWithTitleAndButton = 0;
        foreach ($cards as $card) {
            $leaves = $this->flatten($card->children);
            $hasIconBox = false;
            $hasButton = false;
            foreach ($leaves as $leaf) {
                if ($leaf->type === Block::ICON_BOX && ($leaf->data['title'] ?? '') !== '') {
                    $hasIconBox = true;
                }
                if ($leaf->type === Block::BUTTON) {
                    $hasButton = true;
                }
            }
            if ($hasIconBox && $hasButton) {
                ++$cardsWithTitleAndButton;
            }
        }
        self::assertGreaterThanOrEqual(6, $cardsWithTitleAndButton, 'each service card has a title+description and a button');
    }

    #[Test]
    public function home_renders_to_a_grid_not_a_single_column(): void
    {
        $page = (new ElementorDecoder())->decode($this->request($this->fixture('elementor-home.json')));
        $html = $page->html;

        self::assertStringContainsString('pb-grid', $html, 'rendered output contains a grid');
        self::assertStringContainsString('pb-card', $html, 'rendered output contains cards');
        self::assertStringContainsString('pb-btn', $html, 'rendered output contains buttons');
        self::assertStringContainsString('pb-iconbox', $html, 'rendered output contains icon boxes');
        // Service content survives.
        self::assertStringContainsString('Education Program', $html);
        self::assertStringContainsString('Learn More', $html);
        // No builder leakage in the structured output.
        self::assertStringNotContainsStringIgnoringCase('elementor-', $html);
        self::assertStringNotContainsStringIgnoringCase('<script', $html);
    }

    // ---- design-intent capture ---------------------------------------------

    #[Test]
    public function hero_carries_background_variant_and_min_height(): void
    {
        $page = (new ElementorDecoder())->decode($this->request($this->fixture('elementor-home.json')));
        $first = $page->blocks[0];

        self::assertSame(Block::BAND, $first->type, 'first block is a band (the hero)');
        self::assertSame('gradient', $first->data['variant'] ?? null, 'hero keeps its background variant');
        self::assertGreaterThan(0, (int) ($first->data['min_height'] ?? 0), 'hero keeps its min-height');

        // Rendered as a tall band that honours the captured height.
        self::assertStringContainsString('pb-band--tall', $page->html);
        self::assertStringContainsString('--pb-min-h:', $page->html);
    }

    #[Test]
    public function headings_carry_role_and_alignment(): void
    {
        $page = (new ElementorDecoder())->decode($this->request($this->fixture('elementor-home.json')));
        $headings = \array_values(\array_filter($this->flatten($page->blocks), static fn(Block $b): bool => $b->type === Block::HEADING));

        self::assertNotEmpty($headings);
        // The hero headings are centered in the source; alignment is captured.
        $centered = \array_filter($headings, static fn(Block $b): bool => ($b->data['align'] ?? null) === 'center');
        self::assertNotEmpty($centered, 'at least one heading captured center alignment');
        self::assertStringContainsString('pb-align-center', $page->html);
    }

    #[Test]
    public function buttons_carry_icon_intent_rendered_as_inline_svg(): void
    {
        $page = (new ElementorDecoder())->decode($this->request($this->fixture('elementor-home.json')));
        $buttons = \array_values(\array_filter($this->flatten($page->blocks), static fn(Block $b): bool => $b->type === Block::BUTTON));

        $withIcon = \array_filter($buttons, static fn(Block $b): bool => ($b->data['icon'] ?? '') !== '');
        self::assertNotEmpty($withIcon, 'buttons captured their icon intent');
        // Rendered as a brand inline SVG, not a Font Awesome class.
        self::assertStringContainsString('pb-icon', $page->html);
        self::assertStringContainsString('<svg', $page->html);
        self::assertStringNotContainsStringIgnoringCase('fa-arrow', $page->html, 'no Font Awesome classes leak');
        self::assertStringNotContainsStringIgnoringCase('fas ', $page->html);
    }

    #[Test]
    public function output_has_exactly_one_h1(): void
    {
        foreach (['elementor-home.json', 'elementor-about.json'] as $fixture) {
            $page = (new ElementorDecoder())->decode($this->request($this->fixture($fixture)));
            $count = \preg_match_all('/<h1\b/i', $page->html);
            self::assertSame(1, $count, "exactly one <h1> in {$fixture} (cleaner than the WordPress original)");
        }
    }
}
