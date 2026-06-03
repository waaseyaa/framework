<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\PageBuilder\Block;
use Waaseyaa\PageBuilder\Decoder\ScopedHtmlComponentDecoder;
use Waaseyaa\PageBuilder\DecodeRequest;
use Waaseyaa\PageBuilder\Html\HtmlComponent;
use Waaseyaa\PageBuilder\PageBuilderRegistry;

#[CoversClass(HtmlComponent::class)]
#[CoversClass(ScopedHtmlComponentDecoder::class)]
final class ScopedHtmlComponentTest extends TestCase
{
    private function members(): string
    {
        $path = __DIR__ . '/Fixtures/members-component.html';
        self::assertFileExists($path, 'Synthetic members component fixture must be committed.');

        return (string) \file_get_contents($path);
    }

    #[Test]
    public function it_scopes_css_under_a_generated_class_and_drops_global_rules(): void
    {
        $css = 'body.page .x{display:none}.portal .card{border-radius:14px}@media (max-width:700px){.portal .grid{gap:8px}}';
        $scoped = HtmlComponent::scopeCss($css, 'pbcabc123');

        self::assertStringContainsString('.pbcabc123 .portal .card{', $scoped, 'rules are prefixed with the scope');
        self::assertStringNotContainsString('body.page', $scoped, 'global body rules are dropped');
        self::assertStringContainsString('@media (max-width:700px){', $scoped, 'media query preserved');
        self::assertStringContainsString('.pbcabc123 .portal .grid{', $scoped, 'media inner rules scoped too');
    }

    #[Test]
    public function from_html_returns_null_without_a_style_block(): void
    {
        self::assertNull(HtmlComponent::fromHtml('<div class="card">no style here</div>'));
    }

    #[Test]
    public function it_builds_an_owned_component_from_a_pasted_html_page(): void
    {
        $component = HtmlComponent::fromHtml($this->members());
        self::assertNotNull($component);

        $scope = $component['scope'];
        self::assertMatchesRegularExpression('/^pbc[0-9a-f]{8}$/', $scope);

        // Structure preserved: the portal markup survives (not flattened to text).
        self::assertStringContainsString('class="portal"', $component['html']);
        self::assertStringContainsString('pb-component ' . $scope, $component['html']);
        self::assertStringContainsString('<style>', $component['html']);
        // The card structure survives (not flattened to text).
        self::assertStringContainsString('class="grid"', $component['html']);
        self::assertStringContainsString('class="ttl"', $component['html']);
        self::assertStringContainsString('Quarterly Report 2024', $component['html']);

        // CSS is owned: every rule is under the scope; no global body rules; no
        // un-owned builder leakage.
        self::assertStringContainsString('.' . $scope . ' .portal', $component['css']);
        self::assertSame(0, \preg_match('/(^|})\s*body[ .{]/', $component['css']), 'no global body rules survive');

        // Safety + cleanliness: no scripts, no shortcodes, no event handlers.
        self::assertStringNotContainsStringIgnoringCase('<script', $component['html']);
        self::assertSame(0, \preg_match('/\son[a-z]+=/i', $component['html']), 'no inline event handlers');
        self::assertSame(0, \preg_match('/\[portal_[a-z_]+/i', $component['html']), 'shortcodes stripped');
    }

    #[Test]
    public function registry_routes_pasted_html_to_the_component_decoder(): void
    {
        $registry = PageBuilderRegistry::withDefaults();
        $req = new DecodeRequest(body: '<style>.portal .card{border-radius:14px}</style><div class="portal"><div class="card"><h3>Doc</h3></div></div>');

        $decoder = $registry->decoderFor($req);
        self::assertNotNull($decoder);
        self::assertSame('scoped_html_component', $decoder->id());

        $page = $registry->decode($req);
        self::assertSame(Block::COMPONENT, $page->blocks[0]->type);
        self::assertStringContainsString('class="portal"', $page->html);
        self::assertStringContainsString('<style>', $page->html);
    }
}
