<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Discovery\AiCatalog\AiCatalogEntry;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsAiCatalogEntryProvidersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesAiCatalogEntriesInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

final class ApiServiceProviderAiCatalogTest extends TestCase
{
    #[Test]
    public function catalog_is_absent_by_default_even_when_public_entries_exist(): void
    {
        $provider = $this->provider([]);
        self::assertInstanceOf(AcceptsAiCatalogEntryProvidersInterface::class, $provider);
        $provider->withAiCatalogEntryProviders([$this->contributor()]);
        $provider->boot();

        self::assertNull($this->route($provider));
    }

    #[Test]
    public function enabled_catalog_is_public_get_head_and_requires_installed_entries(): void
    {
        $provider = $this->provider([
            'ai_catalog' => [
                'enabled' => true,
                'base_url' => 'https://cms.example',
                'representative_queries' => [
                    'mcp:public' => ['Find public pages', 'Search public events'],
                ],
            ],
        ]);
        $provider->withAiCatalogEntryProviders([$this->contributor()]);
        $provider->boot();

        $route = $this->route($provider);
        self::assertNotNull($route);
        self::assertSame(AiCatalogEntry::class, get_class($this->contributor()->aiCatalogEntries()[0]));
        self::assertSame('/.well-known/ai-catalog.json', $route->getPath());
        self::assertSame(['GET', 'HEAD'], $route->getMethods());
        self::assertTrue($route->getOption('_public'));
    }

    #[Test]
    public function malformed_gate_fails_boot_without_echoing_the_value(): void
    {
        $provider = $this->provider(['ai_catalog' => ['enabled' => 'yes-secret-value']]);
        $provider->withAiCatalogEntryProviders([$this->contributor()]);

        try {
            $provider->boot();
            self::fail('Expected malformed configuration to fail boot.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('ai_catalog.enabled', $exception->getMessage());
            self::assertStringNotContainsString('yes-secret-value', $exception->getMessage());
        }
    }

    #[Test]
    public function malformed_queries_fail_boot_even_while_surface_is_disabled(): void
    {
        $provider = $this->provider(['ai_catalog' => [
            'enabled' => false,
            'representative_queries' => ['mcp:public' => ['only one private-shaped example']],
        ]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ai_catalog.representative_queries');
        $provider->boot();
    }

    #[Test]
    public function unknown_query_key_fails_boot_even_while_surface_is_disabled(): void
    {
        $provider = $this->provider(['ai_catalog' => [
            'enabled' => false,
            'representative_queries' => [
                'mcp:write' => ['Create private content', 'Delete a private record'],
            ],
        ]]);
        $provider->withAiCatalogEntryProviders([$this->contributor()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ai_catalog');
        $provider->boot();
    }

    #[Test]
    public function enabled_but_empty_catalog_is_withdrawn(): void
    {
        $provider = $this->provider(['ai_catalog' => ['enabled' => true, 'base_url' => 'https://cms.example']]);
        $provider->withAiCatalogEntryProviders([]);
        $provider->boot();

        self::assertNull($this->route($provider));
    }

    /** @param array<string, mixed> $config */
    private function provider(array $config): ApiServiceProvider
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $provider = new ApiServiceProvider();
        $provider->setKernelContext('/tmp/test-project', $config, []);
        $provider->setKernelServices(new class ($manager) implements KernelServicesInterface {
            public function __construct(private readonly EntityTypeManager $manager) {}

            public function get(string $abstract): ?object
            {
                return $abstract === EntityTypeManager::class ? $this->manager : null;
            }
        });
        $provider->register();

        return $provider;
    }

    private function contributor(): ProvidesAiCatalogEntriesInterface
    {
        return new class extends ServiceProvider implements ProvidesAiCatalogEntriesInterface {
            public function register(): void {}

            public function aiCatalogEntries(): array
            {
                return [new AiCatalogEntry(
                    'mcp:public',
                    'Waaseyaa public MCP',
                    'application/json',
                    '/.well-known/mcp.json',
                )];
            }
        };
    }

    private function route(ApiServiceProvider $provider): ?\Symfony\Component\Routing\Route
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $router = new WaaseyaaRouter();
        $provider->routes($router, $manager);

        return $router->getRouteCollection()->get('ai.catalog');
    }
}
