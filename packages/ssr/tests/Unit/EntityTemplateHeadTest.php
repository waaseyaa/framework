<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\Api\Markdown\EntityMarkdownPresenter;
use Waaseyaa\Entity\EntityInterface;

/**
 * Verifies the default `entity.html.twig` renders a full HTML document with the
 * schema.org JSON-LD in `<head>` (FR-014) and the visible Markdown affordance
 * in `<body>` (FR-005), using the real shipped templates.
 */
#[CoversNothing]
final class EntityTemplateHeadTest extends TestCase
{
    private function twig(): Environment
    {
        return new Environment(new FilesystemLoader(\dirname(__DIR__, 2) . '/templates'));
    }

    private function entity(): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('label')->willReturn('Hello World');

        return $entity;
    }

    #[Test]
    public function renders_full_document_with_jsonld_head_and_markdown_affordance(): void
    {
        $html = $this->twig()->render('entity.html.twig', [
            'entity' => $this->entity(),
            'entity_type' => 'node',
            'view_mode' => 'full',
            'fields' => [
                'body' => ['raw' => 'x', 'formatted' => '<p>Body text</p>', 'type' => 'text_long'],
            ],
            // `title` is the access-checked label the caller (SsrPageHandler)
            // resolves via EntityAccessHandler::viewableLabel() (R7 WP1) — the
            // template no longer reads entity.label directly.
            'title' => 'Hello World',
            'schema_org_jsonld' => '<script type="application/ld+json">{"@type":"Article"}</script>',
        ]);

        // Full document, not a fragment.
        self::assertStringContainsString('<!doctype html>', $html);
        self::assertStringContainsString('<head>', $html);
        // JSON-LD landed in <head> (before <body>).
        $headEnd = strpos($html, '</head>');
        $jsonLdPos = strpos($html, 'application/ld+json');
        self::assertNotFalse($jsonLdPos);
        self::assertNotFalse($headEnd);
        self::assertLessThan($headEnd, $jsonLdPos, 'JSON-LD must be inside <head>.');
        // Title from entity label.
        self::assertStringContainsString('<title>Hello World</title>', $html);
        // Body content + the Markdown affordance.
        self::assertStringContainsString('<p>Body text</p>', $html);
        self::assertStringContainsString('rel="alternate" type="text/markdown"', $html);
        self::assertStringContainsString('?raw=1', $html);
    }

    #[Test]
    public function omits_jsonld_when_not_provided(): void
    {
        $html = $this->twig()->render('entity.html.twig', [
            'entity' => $this->entity(),
            'entity_type' => 'node',
            'view_mode' => 'full',
            'fields' => [],
        ]);

        self::assertStringNotContainsString('application/ld+json', $html);
        self::assertStringContainsString('<!doctype html>', $html);
    }

    #[Test]
    public function class_reference_keeps_presenter_dependency_visible(): void
    {
        // Guard: the SSR markdown branch depends on the api presenter; keep the
        // symbol referenced so the dependency is explicit in this package's tests.
        self::assertTrue(class_exists(EntityMarkdownPresenter::class));
    }
}
