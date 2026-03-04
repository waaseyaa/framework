<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SSR\FieldFormatterRegistry;
use Waaseyaa\SSR\Formatter\PlainTextFormatter;

#[CoversClass(FieldFormatterRegistry::class)]
final class FieldFormatterRegistryTest extends TestCase
{
    #[Test]
    public function formats_plain_text_with_html_escaping(): void
    {
        $registry = new FieldFormatterRegistry();

        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', $registry->format('string', '<b>x</b>'));
    }

    #[Test]
    public function formats_html_without_escaping(): void
    {
        $registry = new FieldFormatterRegistry();

        $this->assertSame('<p>ok</p>', $registry->format('text_long', '<p>ok</p>'));
    }

    #[Test]
    public function formats_dates_and_booleans_and_images(): void
    {
        $registry = new FieldFormatterRegistry();

        $this->assertSame('2026-01-05', $registry->format('datetime', 1767571200, ['format' => 'Y-m-d']));
        $this->assertSame('Published', $registry->format('boolean', true, ['true_label' => 'Published']));
        $this->assertSame('<img src="/files/a.jpg" alt="Hero" class="image-style-large">', $registry->format('image', '/files/a.jpg', ['alt' => 'Hero', 'image_style' => 'large']));
    }

    #[Test]
    public function formats_entity_references_as_links(): void
    {
        $registry = new FieldFormatterRegistry();

        $this->assertSame(
            '<a href="/node/42">Read</a>',
            $registry->format('entity_reference', 42, ['url_pattern' => '/node/{id}', 'label' => 'Read']),
        );
    }

    #[Test]
    public function falls_back_to_plain_text_formatter_for_unknown_type(): void
    {
        $registry = new FieldFormatterRegistry();

        $formatter = $registry->get('not_real');
        $this->assertInstanceOf(PlainTextFormatter::class, $formatter);
        $this->assertSame('&lt;ok&gt;', $registry->format('not_real', '<ok>'));
    }
}
