<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase9;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SSR\Attribute\Component;
use Waaseyaa\SSR\ComponentRegistry;

/**
 * Integration tests for component discovery from classes.
 *
 * Exercises: waaseyaa/ssr (ComponentRegistry::registerClass()) with PHP
 * reflection and #[Component] attributes.
 */
#[CoversNothing]
final class SsrComponentDiscoveryIntegrationTest extends TestCase
{
    private ComponentRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ComponentRegistry();
    }

    #[Test]
    public function testRegisterClassReadsComponentAttribute(): void
    {
        $this->registry->registerClass(DiscoverableWidget::class);

        $this->assertTrue($this->registry->has('widget'));

        $metadata = $this->registry->get('widget');
        $this->assertNotNull($metadata);
        $this->assertSame('widget', $metadata->name);
        $this->assertSame('widget.html.twig', $metadata->template);
        $this->assertSame(DiscoverableWidget::class, $metadata->className);
    }

    #[Test]
    public function testRegisterClassThrowsForMissingAttribute(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not have a #[Component] attribute');

        $this->registry->registerClass(PlainClass::class);
    }

    #[Test]
    public function testRegistryContainsAllRegisteredComponents(): void
    {
        $this->registry->registerClass(DiscoverableWidget::class);
        $this->registry->registerClass(DiscoverableNavbar::class);
        $this->registry->registerClass(DiscoverableFooter::class);

        $all = $this->registry->all();

        $this->assertCount(3, $all);

        $names = array_map(fn($meta) => $meta->name, $all);
        $this->assertContains('widget', $names);
        $this->assertContains('navbar', $names);
        $this->assertContains('footer', $names);

        // Verify each component has the correct metadata.
        $widget = $this->registry->get('widget');
        $this->assertSame('widget.html.twig', $widget->template);
        $this->assertSame(DiscoverableWidget::class, $widget->className);

        $navbar = $this->registry->get('navbar');
        $this->assertSame('navbar.html.twig', $navbar->template);
        $this->assertSame(DiscoverableNavbar::class, $navbar->className);

        $footer = $this->registry->get('footer');
        $this->assertSame('footer.html.twig', $footer->template);
        $this->assertSame(DiscoverableFooter::class, $footer->className);
    }
}

// Test component classes for discovery tests.

#[Component(name: 'widget', template: 'widget.html.twig')]
class DiscoverableWidget
{
    public string $title = '';
}

#[Component(name: 'navbar', template: 'navbar.html.twig')]
class DiscoverableNavbar
{
    public string $brand = '';

    /** @var string[] */
    public array $links = [];
}

#[Component(name: 'footer', template: 'footer.html.twig')]
class DiscoverableFooter
{
    public string $copyright = '';
}

/**
 * A plain class without #[Component] attribute for negative testing.
 */
class PlainClass
{
    public string $value = '';
}
