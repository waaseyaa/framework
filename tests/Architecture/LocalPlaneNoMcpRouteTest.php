<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * ADR-022 D-9.1: installing the local development plane MUST register **zero**
 * `/mcp` routes. The ADR names the proof obligation exactly — "a provider test
 * asserting no `/mcp` route exists after installation".
 *
 * ## What is asserted, precisely
 *
 * `waaseyaa/ai-development` (#2655) is a metapackage that owns no code and no
 * provider; what it *adds* to an application is `waaseyaa/ai-agent` and
 * `waaseyaa/testing` (ADR-022 D-2). So this test takes those packages plus
 * `waaseyaa/ai-tools`, where #2657's contracts live, runs every service
 * provider they declare against a real router, and asserts that no `/mcp` route
 * and no `mcp.`-named route appears.
 *
 * **This is deliberately not "zero routes of any kind", and the ADR's wording
 * is not stretched to say so.** `Waaseyaa\AI\Agent\Routing\AgentRouteServiceProvider`
 * registers four `/api/ai/agent/...` routes and always has; that is the
 * pre-existing agent API, not something the local plane introduces, and
 * removing it is no part of this issue. The MCP surface is what D-9.1 is about
 * and what this asserts.
 *
 * ## The second half: the package closure
 *
 * A route assertion only covers providers that are *installed*. The reason no
 * `/mcp` route can appear is structural: `waaseyaa/mcp` is not in the local
 * plane's require closure, so `McpRouteProvider` is not present to register
 * anything. That is the invariant a routine dependency edit could void — one
 * added `require` line, looking like nothing — so it is asserted directly, with
 * a control proving the traversal can actually find what it is looking for.
 */
#[CoversNothing]
final class LocalPlaneNoMcpRouteTest extends TestCase
{
    /**
     * What installing `waaseyaa/ai-development` puts into an application.
     *
     * `ai-agent` and `testing` are its declared direct dependencies (D-2);
     * `ai-tools` is where the transport-neutral dispatch contracts live and is
     * already present in every framework install.
     */
    private const array LOCAL_PLANE_PACKAGES = ['ai-agent', 'ai-tools', 'testing'];

    private const string HTTP_MCP_PACKAGE = 'waaseyaa/mcp';

    #[Test]
    public function no_provider_of_the_local_plane_registers_an_mcp_route(): void
    {
        $routes = $this->routesFor($this->providersOf(self::LOCAL_PLANE_PACKAGES));

        self::assertNotSame([], $routes, 'The provider sweep collected nothing; the assertion would be vacuous.');
        self::assertSame([], $this->mcpRoutesIn($routes), 'A local-plane provider registered an MCP route.');
    }

    /**
     * Seeded control for the route assertion.
     *
     * The same sweep, the same router, the same predicate — with
     * `Waaseyaa\Mcp\McpServiceProvider` added. If `/mcp` does not appear here,
     * then the green result above is measuring nothing.
     */
    #[Test]
    public function the_route_sweep_finds_mcp_routes_when_the_http_package_is_present(): void
    {
        $routes = $this->routesFor([
            ...$this->providersOf(self::LOCAL_PLANE_PACKAGES),
            'Waaseyaa\\Mcp\\McpServiceProvider',
        ]);

        $paths = array_values($this->mcpRoutesIn($routes));
        sort($paths);

        // The discovery card is caught by the route-name half of the predicate
        // (`mcp.server_card`) rather than the path half — deliberately, because
        // a card advertising an MCP server is part of the surface even though
        // its path is `/.well-known/…`.
        self::assertSame(['/.well-known/mcp.json', '/mcp', '/mcp/write'], $paths);
    }

    #[Test]
    public function the_local_plane_require_closure_does_not_reach_the_http_mcp_package(): void
    {
        $closure = $this->requireClosure(array_map(
            static fn(string $package): string => 'waaseyaa/' . $package,
            self::LOCAL_PLANE_PACKAGES,
        ));

        self::assertNotSame([], $closure, 'An empty closure would pass this assertion vacuously.');
        self::assertNotContains(self::HTTP_MCP_PACKAGE, $closure, sprintf(
            'ADR-022 D-1.4: the local plane must not require %s, whose McpRouteProvider registers '
            . '/mcp/write unconditionally on install. Closure walked: %s',
            self::HTTP_MCP_PACKAGE,
            implode(', ', $closure),
        ));
    }

    /**
     * Seeded control for the closure assertion: the traversal detects the
     * package when it IS reachable. Without this, "not in the closure" and "the
     * walker returns nothing useful" are the same green.
     */
    #[Test]
    public function the_closure_walker_finds_the_http_mcp_package_when_it_is_reachable(): void
    {
        $seeded = $this->requireClosure([
            ...array_map(static fn(string $p): string => 'waaseyaa/' . $p, self::LOCAL_PLANE_PACKAGES),
            self::HTTP_MCP_PACKAGE,
        ]);

        self::assertContains(self::HTTP_MCP_PACKAGE, $seeded);
        // And it is genuinely a traversal, not an echo of the seed: requiring
        // mcp drags in its own dependencies too.
        self::assertContains('waaseyaa/routing', $seeded);
    }

    #[Test]
    public function the_extracted_contracts_add_no_service_provider(): void
    {
        // The dispatch contracts are inert library code: nothing binds them,
        // nothing boots them, and nothing routes to them. That is what makes
        // siting them in the production-present waaseyaa/ai-tools safe — a
        // production install gains classes it never constructs.
        $aiTools = $this->manifest('ai-tools');
        self::assertSame(
            ['Waaseyaa\\AI\\Tools\\AiToolsServiceProvider'],
            $aiTools['extra']['waaseyaa']['providers'] ?? [],
            'The extraction must not add a provider to waaseyaa/ai-tools.',
        );
    }

    // ----------------------------------------------------------------------

    /**
     * @param list<string> $packages
     *
     * @return list<class-string>
     */
    private function providersOf(array $packages): array
    {
        $providers = [];
        foreach ($packages as $package) {
            foreach ($this->manifest($package)['extra']['waaseyaa']['providers'] ?? [] as $fqcn) {
                $providers[] = $fqcn;
            }
        }

        return $providers;
    }

    /**
     * Run each provider's `routes()` hook against a real router.
     *
     * A provider that throws fails the test rather than being skipped: a sweep
     * that silently swallows a provider could report "no MCP route" simply
     * because it never asked.
     *
     * @param list<class-string> $providers
     *
     * @return array<string, string> route name => path
     */
    private function routesFor(array $providers): array
    {
        $collected = [];
        foreach ($providers as $fqcn) {
            self::assertTrue(class_exists($fqcn), 'Declared provider is not autoloadable: ' . $fqcn);
            $router = new WaaseyaaRouter(new RequestContext());
            new $fqcn()->routes($router, new EntityTypeManager(new EventDispatcher()));
            foreach ($router->getRouteCollection() as $name => $route) {
                $collected[$name] = $route->getPath();
            }
        }

        return $collected;
    }

    /**
     * @param array<string, string> $routes
     *
     * @return array<string, string>
     */
    private function mcpRoutesIn(array $routes): array
    {
        return array_filter(
            $routes,
            static fn(string $path, string $name): bool => str_starts_with($path, '/mcp')
                || str_starts_with($name, 'mcp.'),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The transitive first-party `require` closure of the supplied packages.
     *
     * Runtime `require` only — `require-dev` is not installed by a consumer, and
     * it is `require` that decides what ends up in an application.
     *
     * @param list<string> $seeds
     *
     * @return list<string>
     */
    private function requireClosure(array $seeds): array
    {
        $seen = [];
        $queue = $seeds;
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name]) || !str_starts_with($name, 'waaseyaa/')) {
                continue;
            }
            $seen[$name] = true;
            $dir = $this->root() . '/packages/' . substr($name, strlen('waaseyaa/'));
            if (!is_file($dir . '/composer.json')) {
                continue;
            }
            $manifest = json_decode((string) file_get_contents($dir . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach (array_keys($manifest['require'] ?? []) as $dependency) {
                if (str_starts_with((string) $dependency, 'waaseyaa/')) {
                    $queue[] = (string) $dependency;
                }
            }
        }

        $closure = array_keys($seen);
        sort($closure);

        return $closure;
    }

    /** @return array<string, mixed> */
    private function manifest(string $package): array
    {
        $file = $this->root() . '/packages/' . $package . '/composer.json';
        self::assertFileExists($file);

        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
