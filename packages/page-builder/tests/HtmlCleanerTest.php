<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\PageBuilder\Html\HtmlCleaner;

#[CoversClass(HtmlCleaner::class)]
final class HtmlCleanerTest extends TestCase
{
    #[Test]
    public function it_drops_scripts_and_styles_with_content(): void
    {
        $c = new HtmlCleaner();
        $out = $c->clean('<p>ok</p><script>alert(1)</script><style>.x{}</style>');

        self::assertStringContainsString('ok', $out);
        self::assertStringNotContainsStringIgnoringCase('alert', $out);
        self::assertStringNotContainsStringIgnoringCase('<script', $out);
        self::assertStringNotContainsStringIgnoringCase('<style', $out);
    }

    #[Test]
    public function it_strips_classes_ids_styles_and_data_attributes(): void
    {
        $c = new HtmlCleaner();
        $out = $c->clean('<p class="elementor-widget" id="w1" style="color:red" data-x="1">hi</p>');

        self::assertStringContainsString('hi', $out);
        self::assertStringNotContainsStringIgnoringCase('class=', $out);
        self::assertStringNotContainsStringIgnoringCase('id=', $out);
        self::assertStringNotContainsStringIgnoringCase('style=', $out);
        self::assertStringNotContainsStringIgnoringCase('data-x', $out);
        self::assertStringNotContainsStringIgnoringCase('elementor', $out);
    }

    #[Test]
    public function it_unwraps_non_semantic_tags_keeping_content(): void
    {
        $c = new HtmlCleaner();
        $out = $c->clean('<div class="wrap"><span>inner</span> text</div>');

        self::assertStringContainsString('inner', $out);
        self::assertStringContainsString('text', $out);
        self::assertStringNotContainsStringIgnoringCase('<div', $out);
        self::assertStringNotContainsStringIgnoringCase('<span', $out);
    }

    #[Test]
    public function it_removes_shortcodes(): void
    {
        $c = new HtmlCleaner();
        $out = $c->clean('<p>before [gallery ids="1,2"] middle [/vc_row] after</p>');

        self::assertStringContainsString('before', $out);
        self::assertStringContainsString('after', $out);
        self::assertStringNotContainsString('[gallery', $out);
        self::assertStringNotContainsString('[/vc_row', $out);
    }

    #[Test]
    public function it_preserves_links_and_images_with_safe_attributes(): void
    {
        $c = new HtmlCleaner();
        $out = $c->clean('<a href="/x" title="t" onclick="bad()">link</a><img src="/i.jpg" alt="a" onerror="x">');

        self::assertStringContainsString('href="/x"', $out);
        self::assertStringContainsString('title="t"', $out);
        self::assertStringContainsString('src="/i.jpg"', $out);
        self::assertStringContainsString('alt="a"', $out);
        self::assertStringNotContainsStringIgnoringCase('onclick', $out);
        self::assertStringNotContainsStringIgnoringCase('onerror', $out);
    }
}
