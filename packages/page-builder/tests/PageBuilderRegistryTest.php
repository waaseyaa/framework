<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\PageBuilder\DecodeRequest;
use Waaseyaa\PageBuilder\PageBuilderRegistry;

#[CoversClass(PageBuilderRegistry::class)]
final class PageBuilderRegistryTest extends TestCase
{
    #[Test]
    public function it_selects_elementor_when_postmeta_present(): void
    {
        $registry = PageBuilderRegistry::withDefaults();
        $req = new DecodeRequest(
            body: '<p>fallback</p>',
            meta: ['_elementor_data' => '[{"elType":"widget","widgetType":"heading","settings":{"title":"Hi","header_size":"h2"}}]'],
        );

        $decoder = $registry->decoderFor($req);
        self::assertNotNull($decoder);
        self::assertSame('elementor', $decoder->id());

        $page = $registry->decode($req);
        self::assertSame('elementor', $page->builder);
        self::assertMatchesRegularExpression('/<h2[^>]*>Hi<\/h2>/', $page->html);
    }

    #[Test]
    public function it_selects_gutenberg_for_block_markup(): void
    {
        $registry = PageBuilderRegistry::withDefaults();
        $req = new DecodeRequest(body: '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->');

        self::assertSame('gutenberg', $registry->decode($req)->builder);
    }

    #[Test]
    public function it_falls_back_to_plain_html(): void
    {
        $registry = PageBuilderRegistry::withDefaults();
        $req = new DecodeRequest(body: '<p>just <strong>plain</strong> html</p>');

        $page = $registry->decode($req);
        self::assertSame('plain_html', $page->builder);
        self::assertStringContainsString('plain', $page->html);
    }

    #[Test]
    public function default_registry_always_decodes(): void
    {
        $registry = PageBuilderRegistry::withDefaults();

        // Even empty input resolves to the fallback, never throws.
        $page = $registry->decode(new DecodeRequest());
        self::assertSame('plain_html', $page->builder);
        self::assertSame('', $page->html);
    }

    #[Test]
    public function decoders_are_ordered_by_priority_descending(): void
    {
        $registry = PageBuilderRegistry::withDefaults();
        $ids = \array_map(static fn($d): string => $d->id(), $registry->decoders());

        self::assertSame('elementor', $ids[0]);
        self::assertSame('plain_html', $ids[\count($ids) - 1]);
    }
}
