<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase9;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\SSR\Attribute\Component;
use Waaseyaa\SSR\ComponentMetadata;
use Waaseyaa\SSR\ComponentRegistry;
use Waaseyaa\SSR\ComponentRenderer;
use Waaseyaa\SSR\SsrController;
use Waaseyaa\Foundation\Http\HttpResponse;

/**
 * Integration tests for SSR rendering pipeline with real Twig.
 *
 * Exercises: waaseyaa/ssr (ComponentRegistry, ComponentRenderer, SsrController,
 * HttpResponse) with Twig\Environment + ArrayLoader.
 */
#[CoversNothing]
final class SsrRenderingIntegrationTest extends TestCase
{
    private ComponentRegistry $registry;
    private Environment $twig;
    private ComponentRenderer $renderer;
    private SsrController $controller;

    protected function setUp(): void
    {
        $this->registry = new ComponentRegistry();

        $this->twig = new Environment(new ArrayLoader([
            'hero.html.twig' => '<section class="hero"><h1>{{ title }}</h1><p>{{ subtitle }}</p></section>',
            'card.html.twig' => '<div class="card"><h2>{{ heading }}</h2><p>{{ body }}</p></div>',
            'alert.html.twig' => '<div class="alert alert-{{ level }}">{{ message }}</div>',
            'greeting.html.twig' => '<span>Hello, {{ name }}! You are {{ age }} years old.</span>',
        ]));

        $this->renderer = new ComponentRenderer($this->twig, $this->registry);
        $this->controller = new SsrController($this->renderer);
    }

    #[Test]
    public function testComponentRegistrationAndRendering(): void
    {
        // Register a component class.
        $this->registry->registerClass(HeroComponent::class);

        // Verify registration.
        $this->assertTrue($this->registry->has('hero'));
        $metadata = $this->registry->get('hero');
        $this->assertNotNull($metadata);
        $this->assertSame('hero', $metadata->name);
        $this->assertSame('hero.html.twig', $metadata->template);
        $this->assertSame(HeroComponent::class, $metadata->className);

        // Render with Twig.
        $html = $this->renderer->render('hero', [
            'title' => 'Welcome to Waaseyaa',
            'subtitle' => 'A modern CMS',
        ]);

        $this->assertStringContainsString('<section class="hero">', $html);
        $this->assertStringContainsString('<h1>Welcome to Waaseyaa</h1>', $html);
        $this->assertStringContainsString('<p>A modern CMS</p>', $html);
    }

    #[Test]
    public function testRenderObjectExtractsPublicProperties(): void
    {
        // Register the greeting component.
        $this->registry->registerClass(GreetingComponent::class);

        // Create a component object with public properties.
        $component = new GreetingComponent();
        $component->name = 'Alice';
        $component->age = 30;

        // Render via renderObject().
        $html = $this->renderer->renderObject($component);

        $this->assertStringContainsString('Hello, Alice!', $html);
        $this->assertStringContainsString('You are 30 years old.', $html);
    }

    #[Test]
    public function testSsrControllerRenderReturnsResponse(): void
    {
        // Register the alert component.
        $this->registry->register(new ComponentMetadata(
            name: 'alert',
            template: 'alert.html.twig',
            className: AlertComponent::class,
        ));

        // Use SsrController to render.
        $response = $this->controller->render('alert', [
            'level' => 'warning',
            'message' => 'Disk space low',
        ]);

        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('<div class="alert alert-warning">', $response->content);
        $this->assertStringContainsString('Disk space low', $response->content);
        $this->assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
    }

    #[Test]
    public function testMultipleComponentsRenderIndependently(): void
    {
        // Register multiple components.
        $this->registry->registerClass(HeroComponent::class);
        $this->registry->register(new ComponentMetadata(
            name: 'card',
            template: 'card.html.twig',
            className: CardComponent::class,
        ));
        $this->registry->register(new ComponentMetadata(
            name: 'alert',
            template: 'alert.html.twig',
            className: AlertComponent::class,
        ));

        // Render each and verify distinct output.
        $heroHtml = $this->renderer->render('hero', [
            'title' => 'Hero Title',
            'subtitle' => 'Hero Sub',
        ]);
        $cardHtml = $this->renderer->render('card', [
            'heading' => 'Card Heading',
            'body' => 'Card content here',
        ]);
        $alertHtml = $this->renderer->render('alert', [
            'level' => 'danger',
            'message' => 'System error',
        ]);

        // Hero output.
        $this->assertStringContainsString('<section class="hero">', $heroHtml);
        $this->assertStringContainsString('Hero Title', $heroHtml);
        $this->assertStringNotContainsString('card', $heroHtml);

        // Card output.
        $this->assertStringContainsString('<div class="card">', $cardHtml);
        $this->assertStringContainsString('Card Heading', $cardHtml);
        $this->assertStringNotContainsString('hero', $cardHtml);

        // Alert output.
        $this->assertStringContainsString('alert-danger', $alertHtml);
        $this->assertStringContainsString('System error', $alertHtml);
        $this->assertStringNotContainsString('card', $alertHtml);
    }
}

// Test component classes with #[Component] attribute.

#[Component(name: 'hero', template: 'hero.html.twig')]
class HeroComponent
{
    public string $title = '';
    public string $subtitle = '';
}

#[Component(name: 'card', template: 'card.html.twig')]
class CardComponent
{
    public string $heading = '';
    public string $body = '';
}

#[Component(name: 'alert', template: 'alert.html.twig')]
class AlertComponent
{
    public string $level = 'info';
    public string $message = '';
}

#[Component(name: 'greeting', template: 'greeting.html.twig')]
class GreetingComponent
{
    public string $name = '';
    public int $age = 0;
}
