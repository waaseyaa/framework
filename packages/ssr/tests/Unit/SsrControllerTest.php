<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use Waaseyaa\SSR\Attribute\Component;
use Waaseyaa\SSR\ComponentMetadata;
use Waaseyaa\SSR\ComponentRegistry;
use Waaseyaa\SSR\ComponentRenderer;
use Waaseyaa\SSR\SsrController;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(SsrController::class)]
final class SsrControllerTest extends TestCase
{
    private SsrController $controller;

    protected function setUp(): void
    {
        $loader = new ArrayLoader([
            'components/page.html.twig' => '<h1>{{ title }}</h1><div>{{ body }}</div>',
        ]);
        $twig = new Environment($loader);

        $registry = new ComponentRegistry();
        $registry->register(new ComponentMetadata(
            name: 'page',
            template: 'components/page.html.twig',
            className: ControllerTestPage::class,
        ));

        $renderer = new ComponentRenderer($twig, $registry);
        $this->controller = new SsrController($renderer);
    }

    #[Test]
    public function renderReturnsSsrResponse(): void
    {
        $response = $this->controller->render('page', [
            'title' => 'Welcome',
            'body' => 'Page content.',
        ]);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('<h1>Welcome</h1><div>Page content.</div>', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function renderObjectReturnsSsrResponse(): void
    {
        $component = new ControllerTestPage(
            title: 'Object Page',
            body: 'Object content.',
        );

        $response = $this->controller->renderObject($component);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('<h1>Object Page</h1><div>Object content.</div>', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
    }
}

#[Component(name: 'page', template: 'components/page.html.twig')]
final class ControllerTestPage
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
    ) {}
}
