<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Twig\Error\LoaderError;
use Waaseyaa\SSR\Tests\Support\InteractsWithRenderer;

#[CoversNothing]
final class InteractsWithRendererTest extends TestCase
{
    use InteractsWithRenderer;

    // ---------------------------------------------------------------
    // render()
    // ---------------------------------------------------------------

    #[Test]
    public function render_returns_rendered_html(): void
    {
        $html = $this->render('<p>{{ greeting }}</p>', ['greeting' => 'Hi']);

        $this->assertSame('<p>Hi</p>', $html);
    }

    #[Test]
    public function render_works_with_empty_context(): void
    {
        $html = $this->render('<p>Static</p>');

        $this->assertSame('<p>Static</p>', $html);
    }

    #[Test]
    public function render_supports_twig_filters_and_tags(): void
    {
        $html = $this->render('{% if show %}<em>{{ text|upper }}</em>{% endif %}', [
            'show' => true,
            'text' => 'hello',
        ]);

        $this->assertSame('<em>HELLO</em>', $html);
    }

    #[Test]
    public function render_throws_on_syntax_error(): void
    {
        $this->expectException(\Twig\Error\SyntaxError::class);
        $this->render('{% if %}broken{% endif %}');
    }

    // ---------------------------------------------------------------
    // assertRenderContains()
    // ---------------------------------------------------------------

    #[Test]
    public function assert_render_contains_passes_when_needle_found(): void
    {
        $this->assertRenderContains('Hello', '<p>Hello World</p>');
    }

    #[Test]
    public function assert_render_contains_passes_with_context(): void
    {
        $this->assertRenderContains('Alice', '<p>{{ name }}</p>', ['name' => 'Alice']);
    }

    #[Test]
    public function assert_render_contains_fails_when_needle_missing(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->assertRenderContains('Missing', '<p>Hello</p>');
    }

    // ---------------------------------------------------------------
    // assertRenderMatches()
    // ---------------------------------------------------------------

    #[Test]
    public function assert_render_matches_passes_on_pattern_match(): void
    {
        $this->assertRenderMatches('/<h1>.*<\/h1>/', '<h1>Title</h1>');
    }

    #[Test]
    public function assert_render_matches_passes_with_context(): void
    {
        $this->assertRenderMatches('/Hello \w+/', '<p>Hello {{ name }}</p>', ['name' => 'Bob']);
    }

    #[Test]
    public function assert_render_matches_fails_on_no_match(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->assertRenderMatches('/^<div>/', '<p>Not a div</p>');
    }

    // ---------------------------------------------------------------
    // Filesystem template support
    // ---------------------------------------------------------------

    #[Test]
    public function render_file_renders_fixture_template(): void
    {
        $fixtureDir = dirname(__DIR__, 2) . '/fixtures';
        $html = $this->renderFile($fixtureDir, 'greeting.html.twig', ['name' => 'Twig']);

        $this->assertStringContainsString('<h1>Hello Twig</h1>', $html);
    }

    #[Test]
    public function render_file_throws_on_missing_template(): void
    {
        $this->expectException(LoaderError::class);
        $this->renderFile(dirname(__DIR__, 2) . '/fixtures', 'nonexistent.html.twig');
    }
}
