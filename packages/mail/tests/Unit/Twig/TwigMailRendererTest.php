<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Mail\Twig\TwigMailRenderer;

#[CoversClass(TwigMailRenderer::class)]
final class TwigMailRendererTest extends TestCase
{
    #[Test]
    public function render_produces_envelope_with_html_body(): void
    {
        $twig = new Environment(new ArrayLoader([
            'welcome.html.twig' => '<h1>Hello {{ name }}</h1>',
        ]));
        $renderer = new TwigMailRenderer($twig);

        $envelope = $renderer->render(
            template: 'welcome.html.twig',
            to: ['user@example.com'],
            from: 'noreply@example.com',
            subject: 'Welcome',
            context: ['name' => 'Alice'],
        );

        $this->assertSame(['user@example.com'], $envelope->to);
        $this->assertSame('noreply@example.com', $envelope->from);
        $this->assertSame('Welcome', $envelope->subject);
        $this->assertSame('<h1>Hello Alice</h1>', $envelope->htmlBody);
        $this->assertSame('', $envelope->textBody);
    }

    #[Test]
    public function render_passes_headers_through(): void
    {
        $twig = new Environment(new ArrayLoader([
            'simple.html.twig' => 'body',
        ]));
        $renderer = new TwigMailRenderer($twig);

        $envelope = $renderer->render(
            template: 'simple.html.twig',
            to: ['a@test.com'],
            from: 'b@test.com',
            subject: 'Test',
            headers: ['X-Priority' => '1'],
        );

        $this->assertSame(['X-Priority' => '1'], $envelope->headers);
    }
}
