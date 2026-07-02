<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Plugin\Process;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[Test]
    public function strips_onerror_nested_inside_disallowed_wrapper(): void
    {
        // B-4 reopened: filterNode() snapshots children once, then
        // replaceWithTextContent() hoists a disallowed wrapper's descendants
        // verbatim without re-checking them. Pre-fix, the <img onerror=…>
        // was hoisted out of <span> untouched and the onerror attribute
        // survived because it never passed back through filterAttributes().
        $p = new HtmlSanitizeProcessor('body');
        $result = $p->transform(null, $this->context([
            'body' => '<p><span><img src=x onerror=alert(1)></span></p>',
        ]));

        self::assertIsString($result);
        self::assertStringNotContainsString('onerror', $result);
    }

    #[Test]
    public function strips_script_nested_inside_disallowed_wrapper(): void
    {
        // Pre-fix: <div> is disallowed and hoists its child <span> up to
        // become a direct child of <body> without re-filtering it. The
        // hoisted <span> was never in the original snapshot, so its own
        // disallowed child <script> was never revisited — both the <script>
        // tag and its raw JS payload text survived intact.
        $p = new HtmlSanitizeProcessor('body');
        $result = $p->transform(null, $this->context([
            'body' => '<div><span><script>alert(1)</script></span></div>',
        ]));

        self::assertIsString($result);
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('alert(1)', $result);
    }

    #[Test]
    public function strips_javascript_href_nested_inside_disallowed_wrapper(): void
    {
        // Pre-fix: <div> is disallowed and hoists its allowlisted <a> child
        // up without re-running filterAttributes() on it, so the scheme
        // check at hasUnsafeUrlScheme() never runs on the promoted node and
        // the javascript: URL survives on an otherwise-allowed <a href>.
        $p = new HtmlSanitizeProcessor('body');
        $result = $p->transform(null, $this->context([
            'body' => '<div><a href="javascript:alert(1)">x</a></div>',
        ]));

        self::assertIsString($result);
        self::assertStringNotContainsString('javascript:', $result);
        self::assertStringContainsString('x', $result);
    }

    #[Test]
    public function strips_data_uri_nested_inside_disallowed_wrapper(): void
    {
        $p = new HtmlSanitizeProcessor('body');
        $result = $p->transform(null, $this->context([
            'body' => '<div><span><a href="data:text/html,<script>alert(1)</script>">x</a></span></div>',
        ]));

        self::assertIsString($result);
        self::assertStringNotContainsString('data:', $result);
    }

    #[Test]
    public function strips_dangerous_payload_under_deep_nesting(): void
    {
        // Proves a fixpoint, not a single extra re-check level: three
        // disallowed wrappers stacked around the payload. A one-level-only
        // fix (re-filtering only the immediate hoist) would still leak this.
        $p = new HtmlSanitizeProcessor('body');
        $result = $p->transform(null, $this->context([
            'body' => '<div><span><b><i><img src=x onerror=alert(1)></i></b></span></div>',
        ]));

        self::assertIsString($result);
        self::assertStringNotContainsString('onerror', $result);
    }

    #[Test]
    public function preserves_benign_content_nested_inside_disallowed_wrapper(): void
    {
        // Regression guard: the fixpoint re-filter must not over-strip
        // allowed content that happens to be nested inside a disallowed
        // wrapper — <p>hello</p> should survive the <div> being stripped.
        $p = new HtmlSanitizeProcessor('body');
        $result = $p->transform(null, $this->context([
            'body' => '<div><p>hello</p></div>',
        ]));

        self::assertIsString($result);
        self::assertStringContainsString('hello', $result);
        self::assertStringContainsString('<p>', $result);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function cdataContentModelWrappers(): array
    {
        // libxml2 parses the inner content of each of these tags as a raw
        // \DOMCdataSection (CDATA content model), which saveHTML() would emit
        // VERBATIM as live markup — a stored-XSS bypass with no nesting needed.
        return [
            'xmp' => ['xmp'],
            'iframe' => ['iframe'],
            'noembed' => ['noembed'],
            'noframes' => ['noframes'],
            'plaintext' => ['plaintext'],
        ];
    }

    #[Test]
    #[DataProvider('cdataContentModelWrappers')]
    public function neutralizes_script_inside_cdata_content_model_wrapper(string $wrapper): void
    {
        // Pre-fix: each of these CDATA-content-model tags parsed its <script>
        // payload as a \DOMCdataSection, which applyChildPolicy() skipped (only
        // \DOMElement was re-checked) and saveHTML() serialized verbatim — a
        // LIVE <script> survived in the output with no nesting required.
        $p = new HtmlSanitizeProcessor('body');
        $result = $p->transform(null, $this->context([
            'body' => "<{$wrapper}><script>alert(1)</script></{$wrapper}>",
        ]));

        self::assertIsString($result);
        // No live <script> tag — the escaped literal `&lt;script&gt;` is fine.
        self::assertStringNotContainsString('<script', $result);
    }

    #[Test]
    public function neutralizes_onerror_img_inside_cdata_content_model_wrapper(): void
    {
        // Pre-fix: <xmp><img onerror=…> serialized the raw CDATA verbatim, so a
        // LIVE <img> with an onerror handler survived.
        $p = new HtmlSanitizeProcessor('body');
        $result = $p->transform(null, $this->context([
            'body' => '<xmp><img src=x onerror=alert(9)></xmp>',
        ]));

        self::assertIsString($result);
        // The security invariant is that no LIVE <img> carrying an onerror
        // handler survives. libxml parses <xmp> content differently across
        // versions: newer libxml keeps it as a CDATA section that is
        // entity-escaped to inert text (no live <img> at all), older libxml
        // re-parses the payload into a real <img> whose onerror is then
        // stripped (a clean, allowlisted <img src="x">). Both are safe, so
        // assert the invariant directly (no live img-with-onerror) rather than
        // the version-specific serialization.
        // (An escaped-text form legitimately contains the inert characters
        // "onerror=alert(9)" as literal text, so match only a LIVE element.)
        self::assertDoesNotMatchRegularExpression('/<img[^>]*onerror/i', $result);
    }

    #[Test]
    public function neutralizes_script_inside_nested_cdata_content_model_wrapper(): void
    {
        // The CDATA neutralization must survive the hoist path too: <xmp> is
        // itself nested inside disallowed <span>/<div> wrappers, so it is
        // reached only after those are stripped and its CDATA payload promoted.
        $p = new HtmlSanitizeProcessor('body');
        $result = $p->transform(null, $this->context([
            'body' => '<div><span><xmp><script>alert(3)</script></xmp></span></div>',
        ]));

        self::assertIsString($result);
        self::assertStringNotContainsString('<script', $result);
    }

    #[Test]
    public function preserves_benign_multibyte_text_inside_cdata_content_model_wrapper(): void
    {
        // Regression guard: the CDATA-to-escaped-text conversion must not drop
        // or corrupt the author's visible text, including Indigenous
        // orthography (ā, ʼ, ᐊᓂᔑᓈᐯ) carried through a CDATA-model wrapper.
        $p = new HtmlSanitizeProcessor('body');
        $result = $p->transform(null, $this->context([
            'body' => '<xmp>ordinary text with ā and ʼ and ᐊᓂᔑᓈᐯ</xmp>',
        ]));

        self::assertIsString($result);
        self::assertStringContainsString('ordinary text with ā and ʼ and ᐊᓂᔑᓈᐯ', $result);
    }

    #[Test]
    public function neutralizes_cdata_when_wrapper_tag_is_custom_allowlisted(): void
    {
        // Belt-and-suspenders: a custom allowlist that KEEPS a CDATA-content-
        // model tag (here <xmp>) must still neutralize its raw CDATA payload —
        // the direct-child CDATA path in filterNode(), not the hoist path.
        $p = new HtmlSanitizeProcessor(
            sourceField: 'body',
            allowedTags: ['xmp'],
            allowedAttributes: [],
        );
        $result = $p->transform(null, $this->context([
            'body' => '<xmp><script>alert(1)</script></xmp>',
        ]));

        self::assertIsString($result);
        self::assertStringNotContainsString('<script', $result);
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
