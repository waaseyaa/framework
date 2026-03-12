<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\SSR\TwigErrorPageRenderer;

#[CoversClass(TwigErrorPageRenderer::class)]
final class TwigErrorPageRendererTest extends TestCase
{
    #[Test]
    public function renders_template_when_available(): void
    {
        $twig = new Environment(new ArrayLoader([
            '403.html.twig' => '<h1>{{ status_code }} {{ title }}</h1><p>{{ detail }}</p><a href="/login?redirect={{ request_path }}">Sign in</a>',
        ]));

        $renderer = new TwigErrorPageRenderer($twig);
        $request = Request::create('/dashboard/volunteer');

        $response = $renderer->render(403, 'Forbidden', 'You do not have permission.', $request);

        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('403 Forbidden', $response->getContent());
        $this->assertStringContainsString('You do not have permission.', $response->getContent());
        $this->assertStringContainsString('/dashboard/volunteer', $response->getContent());
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function returns_null_when_template_missing(): void
    {
        $twig = new Environment(new ArrayLoader([]));

        $renderer = new TwigErrorPageRenderer($twig);
        $request = Request::create('/admin/settings');

        $response = $renderer->render(403, 'Forbidden', 'Access denied.', $request);

        $this->assertNull($response);
    }

    #[Test]
    public function returns_null_when_template_throws(): void
    {
        $twig = new Environment(new ArrayLoader([
            '500.html.twig' => '{{ undefined_function() }}',
        ]));

        $renderer = new TwigErrorPageRenderer($twig);
        $request = Request::create('/broken');

        $response = $renderer->render(500, 'Internal Server Error', 'Something broke.', $request);

        $this->assertNull($response);
    }

    #[Test]
    public function renders_different_status_codes(): void
    {
        $twig = new Environment(new ArrayLoader([
            '404.html.twig' => '<h1>{{ status_code }} {{ title }}</h1>',
        ]));

        $renderer = new TwigErrorPageRenderer($twig);
        $request = Request::create('/nonexistent');

        $response = $renderer->render(404, 'Not Found', 'Page not found.', $request);

        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('404 Not Found', $response->getContent());
    }
}
