<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Sanitizer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Sanitizer\RichTextSanitizer;

/**
 * Unit coverage for the RichTextSanitizer introduced in R13 WP2 (audit A11,
 * SECURITY). See JsonApiControllerRichTextSanitizationTest,
 * EntityTypeBuilderRichTextSanitizationTest, and
 * FieldAutoSaveRichTextSanitizationTest for the exploit-level (chokepoint)
 * proofs; this file exercises the sanitizer class in isolation.
 */
#[CoversClass(RichTextSanitizer::class)]
final class RichTextSanitizerTest extends TestCase
{
    private RichTextSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new RichTextSanitizer();
    }

    #[Test]
    public function onlyTextLongIsAnHtmlFieldType(): void
    {
        $this->assertTrue(RichTextSanitizer::isHtmlFieldType('text_long'));
        $this->assertFalse(RichTextSanitizer::isHtmlFieldType('string'));
        $this->assertFalse(RichTextSanitizer::isHtmlFieldType('text'));
        $this->assertFalse(RichTextSanitizer::isHtmlFieldType('email'));
        $this->assertFalse(RichTextSanitizer::isHtmlFieldType('integer'));
    }

    #[Test]
    public function stripsScriptTagAndItsContent(): void
    {
        $out = $this->sanitizer->sanitize('<p>hi</p><script>alert(document.cookie)</script>');

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(document.cookie)', $out);
        $this->assertStringContainsString('<p>hi</p>', $out);
    }

    #[Test]
    public function stripsInlineEventHandlerAttribute(): void
    {
        $out = $this->sanitizer->sanitize('<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
    }

    #[Test]
    public function preservesSafeMarkup(): void
    {
        $safe = '<p><strong><a href="https://example.com">link</a></strong></p>';
        $out = $this->sanitizer->sanitize($safe);

        $this->assertSame($safe, $out);
    }

    #[Test]
    public function preservesIndigenousOrthographyWhileStrippingScript(): void
    {
        $payload = "<p>Aaniin, Anishinaabemowin: \u{1401}\u{1489}\u{1591}\u{140b}"
            . "\u{1490}\u{140e}\u{1360}\u{1490}\u{1370}\u{1550}\u{1400}\u{140d}, "
            . "gichi-mookomaan, macron \u{101}, o'ow, nake'</p><script>alert(1)</script>";

        $out = $this->sanitizer->sanitize($payload);
        $decoded = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString('Aaniin, Anishinaabemowin:', $decoded);
        $this->assertStringContainsString("\u{1401}\u{1489}\u{1591}\u{140b}", $decoded);
        $this->assertStringContainsString('gichi-mookomaan', $decoded);
        $this->assertStringContainsString("\u{101}", $decoded);
        $this->assertStringContainsString("o'ow", $decoded);
        $this->assertStringContainsString("nake'", $decoded);
        $this->assertStringContainsString('<p>', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    #[Test]
    public function sanitizeValuePassesThroughNullAndNonStringScalars(): void
    {
        $this->assertNull($this->sanitizer->sanitizeValue(null));
        $this->assertSame(42, $this->sanitizer->sanitizeValue(42));
        $this->assertTrue($this->sanitizer->sanitizeValue(true));
    }

    #[Test]
    public function sanitizeValueRecursesIntoArraysForMultiValueFields(): void
    {
        $result = $this->sanitizer->sanitizeValue([
            '<p>ok</p><script>alert(1)</script>',
            '<p>also ok</p>',
        ]);

        $this->assertIsArray($result);
        $this->assertStringNotContainsString('<script', $result[0]);
        $this->assertStringContainsString('<p>ok</p>', $result[0]);
        $this->assertSame('<p>also ok</p>', $result[1]);
    }

    #[Test]
    public function emptyStringSanitizesToEmptyString(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(''));
    }
}
