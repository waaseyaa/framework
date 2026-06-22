<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Plugin\Process;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Migration\Plugin\Process\HtmlSanitizeProcessor;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ReservedPluginIds;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\SourceId;

#[CoversClass(HtmlSanitizeProcessor::class)]
final class HtmlSanitizeProcessorTest extends TestCase
{
    #[Test]
    public function id_is_html_sanitize(): void
    {
        $p = new HtmlSanitizeProcessor('body');
        self::assertSame(ReservedPluginIds::HTML_SANITIZE, $p->id());
        self::assertSame('stable', $p->stability());
    }

    #[Test]
    public function preserves_safe_links_with_href(): void
    {
        $p = new HtmlSanitizeProcessor('body');
        $ctx = $this->context([
            'body' => '<p>Hello <a href="https://example.com">friend</a></p>',
        ]);

        $result = $p->transform(null, $ctx);

        self::assertIsString($result);
        self::assertStringContainsString('<a href="https://example.com">friend</a>', $result);
        self::assertStringContainsString('<p>Hello', $result);
    }

    #[Test]
    public function strips_script_tags_and_their_content(): void
    {
        $p = new HtmlSanitizeProcessor('body');
        $ctx = $this->context([
            'body' => '<p>safe</p><script>alert("xss")</script>',
        ]);

        $result = $p->transform(null, $ctx);

        self::assertIsString($result);
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('alert', $result);
        self::assertStringContainsString('safe', $result);
    }

    #[Test]
    public function strips_disallowed_attributes(): void
    {
        $p = new HtmlSanitizeProcessor('body');
        $ctx = $this->context([
            'body' => '<a href="https://example.com" onclick="alert(1)">x</a>',
        ]);

        $result = $p->transform(null, $ctx);

        self::assertIsString($result);
        self::assertStringContainsString('href="https://example.com"', $result);
        self::assertStringNotContainsString('onclick', $result);
    }

    #[Test]
    public function null_passes_through(): void
    {
        $p = new HtmlSanitizeProcessor('body');
        $ctx = $this->context([]);

        self::assertNull($p->transform(null, $ctx));
    }

    #[Test]
    public function empty_string_returns_empty(): void
    {
        $p = new HtmlSanitizeProcessor('body');
        $ctx = $this->context(['body' => '']);

        // null input falls back to source-record value (`''` → returned as `''`).
        self::assertSame('', $p->transform(null, $ctx));
    }

    #[Test]
    public function handles_malformed_input_without_raising(): void
    {
        $p = new HtmlSanitizeProcessor('body');
        $ctx = $this->context([
            'body' => '<p>unclosed <strong>bold <em>both',
        ]);

        $result = $p->transform(null, $ctx);

        self::assertIsString($result);
        // Should at least retain text content; tag closing may be auto-corrected.
        self::assertStringContainsString('unclosed', $result);
        self::assertStringContainsString('bold', $result);
        self::assertStringContainsString('both', $result);
    }

    #[Test]
    public function custom_allowlists_are_honoured(): void
    {
        $p = new HtmlSanitizeProcessor(
            sourceField: 'body',
            allowedTags: ['span'],
            allowedAttributes: ['span' => ['class']],
        );
        $ctx = $this->context([
            'body' => '<span class="x" data-evil="y"><p>nope</p></span>',
        ]);

        $result = $p->transform(null, $ctx);

        self::assertIsString($result);
        self::assertStringContainsString('<span class="x">', $result);
        self::assertStringNotContainsString('data-evil', $result);
        self::assertStringNotContainsString('<p>', $result);
        // Text content of stripped <p> is preserved.
        self::assertStringContainsString('nope', $result);
    }

    #[Test]
    public function rejects_empty_source_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HtmlSanitizeProcessor('');
    }

    #[Test]
    public function accepts_chained_value_when_present(): void
    {
        $p = new HtmlSanitizeProcessor('body');
        $ctx = $this->context(['body' => 'ignored-because-chain-provides-value']);

        $result = $p->transform('<p>chained</p>', $ctx);

        self::assertIsString($result);
        self::assertStringContainsString('chained', $result);
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    public function strips_javascript_href(): void
    {
        $p = new HtmlSanitizeProcessor('body');
        $result = $p->transform(null, $this->context([
            'body' => '<a href="javascript:alert(document.cookie)">click</a>',
        ]));

        self::assertIsString($result);
        self::assertStringNotContainsString('javascript:', $result);
        self::assertStringNotContainsString('href', $result);
        self::assertStringContainsString('click', $result);
    }

    #[Test]
    public function strips_data_uri_image_src(): void
    {
        $p = new HtmlSanitizeProcessor('body');
        $result = $p->transform(null, $this->context([
            'body' => '<img src="data:text/html;base64,PHNjcmlwdD4=" alt="x">',
        ]));

        self::assertIsString($result);
        self::assertStringNotContainsString('data:', $result);
        self::assertStringNotContainsString('src', $result);
    }

    #[Test]
    public function strips_vbscript_href(): void
    {
        $p = new HtmlSanitizeProcessor('body');
        $result = $p->transform(null, $this->context([
            'body' => '<a href="vbscript:msgbox(1)">x</a>',
        ]));

        self::assertIsString($result);
        self::assertStringNotContainsString('vbscript', $result);
    }

    #[Test]
    public function strips_obfuscated_javascript_scheme(): void
    {
        $p = new HtmlSanitizeProcessor('body');
        // Mixed case + an embedded tab — browsers ignore both when resolving the scheme.
        $result = $p->transform(null, $this->context([
            'body' => "<a href=\"Ja\tVaScRiPt:alert(1)\">x</a>",
        ]));

        self::assertIsString($result);
        self::assertStringNotContainsStringIgnoringCase('javascript', $result);
        self::assertStringNotContainsString('href', $result);
    }

    #[Test]
    public function preserves_relative_mailto_and_fragment_hrefs(): void
    {
        $p = new HtmlSanitizeProcessor('body');

        $relative = $p->transform(null, $this->context(['body' => '<a href="/docs/page">x</a>']));
        $mailto = $p->transform(null, $this->context(['body' => '<a href="mailto:a@b.com">x</a>']));
        $fragment = $p->transform(null, $this->context(['body' => '<a href="#section">x</a>']));

        self::assertIsString($relative);
        self::assertStringContainsString('href="/docs/page"', $relative);
        self::assertIsString($mailto);
        self::assertStringContainsString('href="mailto:a@b.com"', $mailto);
        self::assertIsString($fragment);
        self::assertStringContainsString('href="#section"', $fragment);
    }

    private function context(array $fields): ProcessContext
    {
        return new ProcessContext(
            sourceRecord: new SourceRecord('wp', $fields),
            migrationId: 'm1',
            destinationField: 'body',
            lookup: static fn (string $m, SourceId $id): ?WriteResult => null,
        );
    }
}
