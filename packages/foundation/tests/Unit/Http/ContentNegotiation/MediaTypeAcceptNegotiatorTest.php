<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\ContentNegotiation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Http\ContentNegotiation\MediaTypeAcceptNegotiator;

#[CoversClass(MediaTypeAcceptNegotiator::class)]
final class MediaTypeAcceptNegotiatorTest extends TestCase
{
    private const HTML = MediaTypeAcceptNegotiator::HTML;
    private const MD = MediaTypeAcceptNegotiator::MARKDOWN;

    private MediaTypeAcceptNegotiator $negotiator;

    /** @var list<string> */
    private array $supported;

    protected function setUp(): void
    {
        $this->negotiator = new MediaTypeAcceptNegotiator();
        $this->supported = [self::HTML, self::MD];
    }

    /**
     * @param list<string> $supported
     */
    #[Test]
    #[DataProvider('negotiationCases')]
    public function it_negotiates_the_expected_media_type(string $accept, array $supported, string $default, string $expected): void
    {
        self::assertSame($expected, $this->negotiator->negotiate($accept, $supported, $default));
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>, 2: string, 3: string}>
     */
    public static function negotiationCases(): iterable
    {
        $supported = [self::HTML, self::MD];

        yield 'empty header -> default' => ['', $supported, self::HTML, self::HTML];
        yield 'explicit markdown' => ['text/markdown', $supported, self::HTML, self::MD];
        yield 'explicit html' => ['text/html', $supported, self::HTML, self::HTML];
        yield 'browser default */*' => ['text/html,application/xhtml+xml,*/*;q=0.8', $supported, self::HTML, self::HTML];
        yield 'markdown preferred by quality' => ['text/html;q=0.5,text/markdown;q=0.9', $supported, self::HTML, self::MD];
        yield 'html preferred by quality' => ['text/html;q=0.9,text/markdown;q=0.5', $supported, self::HTML, self::HTML];
        yield 'wildcard only -> server preference (first supported)' => ['*/*', $supported, self::HTML, self::HTML];
        yield 'type wildcard text/*' => ['text/*', $supported, self::HTML, self::HTML];
        yield 'unsupported type -> default' => ['application/json', $supported, self::HTML, self::HTML];
        yield 'q=0 rejects markdown, html via */*' => ['text/markdown;q=0,*/*', $supported, self::HTML, self::HTML];
        // RFC 7231 §5.3.2: the most specific range sets a type's quality, then
        // types compete on that resolved quality. Here markdown is pinned to
        // 0.4 by its specific range while html rides */* at 0.9 -> html wins.
        yield 'specific range pins quality, broader range still wins' => ['text/markdown;q=0.4,*/*;q=0.9', $supported, self::HTML, self::HTML];
        // Genuine exact-beats-wildcard: markdown exact (1.0) vs html via */* (0.1).
        yield 'exact match beats a lower wildcard' => ['text/markdown,*/*;q=0.1', $supported, self::HTML, self::MD];
        yield 'case-insensitive media range' => ['TEXT/MARKDOWN', $supported, self::HTML, self::MD];
        yield 'whitespace tolerated' => ['  text/markdown ; q=0.9 ', $supported, self::HTML, self::MD];
        yield 'tie resolves to server preference order' => ['text/markdown;q=0.7,text/html;q=0.7', $supported, self::HTML, self::HTML];
        yield 'empty supported -> default' => ['text/markdown', [], self::HTML, self::HTML];
    }

    #[Test]
    public function query_override_raw_selects_markdown(): void
    {
        self::assertSame(self::MD, $this->negotiator->resolveQueryOverride(['raw' => ''], $this->supported));
        self::assertSame(self::MD, $this->negotiator->resolveQueryOverride(['raw' => '1'], $this->supported));
    }

    #[Test]
    public function query_override_format_md_and_html(): void
    {
        self::assertSame(self::MD, $this->negotiator->resolveQueryOverride(['format' => 'md'], $this->supported));
        self::assertSame(self::MD, $this->negotiator->resolveQueryOverride(['format' => 'markdown'], $this->supported));
        self::assertSame(self::HTML, $this->negotiator->resolveQueryOverride(['format' => 'html'], $this->supported));
    }

    #[Test]
    public function query_override_absent_returns_null(): void
    {
        self::assertNull($this->negotiator->resolveQueryOverride([], $this->supported));
        self::assertNull($this->negotiator->resolveQueryOverride(['page' => '2'], $this->supported));
    }

    #[Test]
    public function query_override_unknown_format_returns_null(): void
    {
        self::assertNull($this->negotiator->resolveQueryOverride(['format' => 'pdf'], $this->supported));
    }

    #[Test]
    public function query_override_not_in_supported_returns_null(): void
    {
        // Markdown requested via ?raw but server only supports HTML.
        self::assertNull($this->negotiator->resolveQueryOverride(['raw' => ''], [self::HTML]));
    }

    #[Test]
    public function raw_takes_precedence_then_format_overrides_it(): void
    {
        // format=html should win over a bare ?raw when both are present.
        self::assertSame(self::HTML, $this->negotiator->resolveQueryOverride(['raw' => '', 'format' => 'html'], $this->supported));
    }
}
