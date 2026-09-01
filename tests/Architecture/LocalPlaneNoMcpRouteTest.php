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
use Waaseyaa\Testing\Filesystem\TemporaryDirectory;

/**
 * ADR-022 D-9.1: installing the local development plane MUST register **zero**
 * `/mcp` routes. The ADR names the proof obligation exactly — "a provider test
 * asserting no `/mcp` route exists after installation".
 *
 * ## What is asserted, precisely
 *
 * `waaseyaa/ai-development` (#2655) is a metapackage that owns no code and no
 * provider; what it *adds* to an application is its own `require` closure. So
 * this test derives that closure from `packages/ai-development/composer.json`
 * itself — never from a list maintained independently of it (#2781) — runs
 * every service provider declared by every package the closure reaches
 * against a real router, and asserts that no `/mcp` route and no
 * `mcp.`-named route appears.
 *
 * **This is deliberately not "zero routes of any kind", and the ADR's wording
 * is not stretched to say so.** `Waaseyaa\AI\Agent\Routing\AgentRouteServiceProvider`
 * registers four `/api/ai/agent/...` routes and always has; that is the
 * pre-existing agent API, not something the local plane introduces, and
 * removing it is no part of this issue. The MCP surface is what D-9.1 is about
 * and what this asserts.
 *
 * ## The package closure
 *
 * A route assertion only covers providers that are *installed*. The reason no
 * `/mcp` route can appear is structural: `waaseyaa/mcp` is not in the local
 * plane's require closure, so `McpRouteProvider` is not present to register
 * anything. That is the invariant a routine dependency edit could void — one
 * added `require` line, looking like nothing — so it is asserted directly
 * against the closure of the metapackage manifest itself, with a control
 * proving the traversal can actually find what it is looking for.
 *
 * ## Providers that need a full application container (#2781)
 *
 * The real closure of `waaseyaa/ai-development` is wide — it reaches
 * `waaseyaa/routing` and `waaseyaa/auth`, among many others, because
 * `waaseyaa/ai-agent` requires `waaseyaa/api`, which requires them. Every
 * provider this closure reaches is executed directly (bare `EntityTypeManager`
 * + router, no application container) *except* one:
 * `Waaseyaa\Routing\AuthOidcRouteServiceProvider::routes()` unconditionally
 * resolves auth/OIDC collaborators (`AuthConfig`, `TwoFactorService`, ...)
 * that exist only inside a fully booted application — a property of that
 * provider unrelated to the local plane (`waaseyaa/routing` and
 * `waaseyaa/auth` are already part of every `waaseyaa/core` production
 * closure; see `AuthOidcRouteServiceProviderTest` for its dedicated,
 * fixture-heavy coverage). Building a working DI container inline here to
 * accommodate it is not viable (attempted and abandoned — cross-provider
 * resolution against every closure member recurses without bound). Instead,
 * a provider whose `routes()` fails on exactly that "no binding registered"
 * signal falls back to a literal source scan for the same `/mcp` / `mcp.`
 * predicate: it is still swept, just not by execution. Any other exception
 * still fails the test — the "throw fails, not skips" integrity guarantee
 * below is preserved for everything the sweep cannot explain.
 */
#[CoversNothing]
final class LocalPlaneNoMcpRouteTest extends TestCase
{
    private const string HTTP_MCP_PACKAGE = 'waaseyaa/mcp';

    private const string DEVELOPMENT_PLANE_PACKAGE = 'waaseyaa/ai-development';

    #[Test]
    public function no_provider_of_the_local_plane_registers_an_mcp_route(): void
    {
        $providers = $this->providersOf($this->derivedLocalPlanePackages());
        self::assertNotSame([], $providers, 'The derived closure declared no providers; the assertion would be vacuous.');

        $swept = $this->routesFor($providers);

        self::assertNotSame([], $swept['routes'], 'The provider sweep collected nothing; the assertion would be vacuous.');
        self::assertSame([], $this->mcpRoutesIn($swept['routes']), 'A local-plane provider registered an MCP route.');

        // Non-vacuity for the container-only fallback: if nothing in today's
        // closure ever needs it, it is untested dead code and should be
        // deleted along with its source-scan test rather than left standing.
        self::assertNotSame(
            [],
            $swept['containerOnly'],
            'No provider needed the container-only fallback. If this closure genuinely no longer reaches one, '
            . 'delete the fallback (and the_source_scan_detects_an_mcp_route_literal) rather than leave it unexercised.',
        );

        foreach ($swept['containerOnly'] as $fqcn) {
            self::assertSame([], $this->mcpSignatureInSource($fqcn), sprintf(
                '%s could not be executed without a full application container, so its route literals were '
                . 'checked by source scan instead of execution; the scan found a /mcp or mcp.-prefixed literal.',
                $fqcn,
            ));
        }
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
        $swept = $this->routesFor([
            ...$this->providersOf($this->derivedLocalPlanePackages()),
            'Waaseyaa\\Mcp\\McpServiceProvider',
        ]);

        $paths = array_values($this->mcpRoutesIn($swept['routes']));
        sort($paths);

        // The discovery card is caught by the route-name half of the predicate
        // (`mcp.server_card`) rather than the path half — deliberately, because
        // a card advertising an MCP server is part of the surface even though
        // its path is `/.well-known/…`.
        self::assertSame(['/.well-known/mcp.json', '/mcp', '/mcp/write'], $paths);
    }

    /**
     * Non-vacuity control for the container-only fallback itself: prove the
     * source scan actually finds an MCP route literal in a class that
     * genuinely declares one. Without this, a fallback that always reports
     * "clean" would be indistinguishable from a fallback that works.
     */
    #[Test]
    public function the_source_scan_detects_an_mcp_route_literal(): void
    {
        self::assertNotSame([], $this->mcpSignatureInSource('Waaseyaa\\Mcp\\McpServiceProvider'));
    }

    #[Test]
    public function the_local_plane_require_closure_does_not_reach_the_http_mcp_package(): void
    {
        $closure = $this->requireClosure([self::DEVELOPMENT_PLANE_PACKAGE]);

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
     * package when it IS reachable, and genuinely traverses its dependencies
     * rather than echoing the seed. Seeded independently of the local plane
     * (not mixed with `ai-development`'s own closure) so this proves the
     * generic walker, not a property specific to today's package graph.
     */
    #[Test]
    public function the_closure_walker_finds_the_http_mcp_package_when_it_is_reachable(): void
    {
        $seeded = $this->requireClosure([self::HTTP_MCP_PACKAGE]);

        self::assertContains(self::HTTP_MCP_PACKAGE, $seeded);
        self::assertContains('waaseyaa/routing', $seeded);
    }

    /**
     * Acceptance: "a seeded manifest mutation proving a newly reachable
     * MCP/provider package makes the test fail." The two controls above prove
     * the walker and an in-memory seed detect reachability; this proves the
     * same thing when reachability is introduced by an actual composer.json
     * edit under a disposable `packages/ai-development` fixture — the real
     * drift this issue exists to catch, not a seed this test constructed by
     * hand. The repository tree is never written to.
     */
    #[Test]
    public function a_manifest_edit_reaching_the_http_mcp_package_is_visible_to_the_derived_closure(): void
    {
        $temporary = new TemporaryDirectory('waaseyaa-local-plane-closure-');

        try {
            $this->writeManifest($temporary, 'packages/ai-development/composer.json', [
                'name' => self::DEVELOPMENT_PLANE_PACKAGE,
                'type' => 'metapackage',
                'require' => ['waaseyaa/fake-local-plane-member' => '^0.1.0'],
            ]);
            $this->writeManifest($temporary, 'packages/fake-local-plane-member/composer.json', [
                'name' => 'waaseyaa/fake-local-plane-member',
                'require' => [self::HTTP_MCP_PACKAGE => '^0.1.0'],
            ]);
            $this->writeManifest($temporary, 'packages/mcp/composer.json', [
                'name' => self::HTTP_MCP_PACKAGE,
                'require' => [],
            ]);

            $closure = $this->requireClosure([self::DEVELOPMENT_PLANE_PACKAGE], $temporary->path());

            self::assertContains(self::HTTP_MCP_PACKAGE, $closure, 'A composer.json edit under the ai-development '
                . 'closure that reaches waaseyaa/mcp must be visible to the derived closure this test sweeps — '
                . 'otherwise the derivation is not actually reading the manifest it claims to.');
        } finally {
            $temporary->remove();
        }
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
     * What installing `waaseyaa/ai-development` puts into an application: the
     * transitive first-party runtime closure of its own manifest. This IS the
     * authority (#2781) — no independently maintained mirror of it.
     *
     * @return list<string> short package names (no `waaseyaa/` prefix), for use with providersOf()/manifest()
     */
    private function derivedLocalPlanePackages(): array
    {
        $closure = $this->requireClosure([self::DEVELOPMENT_PLANE_PACKAGE]);

        return array_values(array_map(
            static fn(string $package): string => substr($package, strlen('waaseyaa/')),
            $closure,
        ));
    }

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
     * A provider that throws for any reason other than the specific "no
     * container" signal fails the test rather than being skipped: a sweep
     * that silently swallowed a provider could report "no MCP route" simply
     * because it never asked. See the class docblock for the one documented
     * exception and why it is handled by source scan instead.
     *
     * @param list<class-string> $providers
     *
     * @return array{routes: array<string, string>, containerOnly: list<class-string>}
     */
    private function routesFor(array $providers): array
    {
        $collected = [];
        $containerOnly = [];
        foreach ($providers as $fqcn) {
            self::assertTrue(class_exists($fqcn), 'Declared provider is not autoloadable: ' . $fqcn);

            $router = new WaaseyaaRouter(new RequestContext());
            try {
                new $fqcn()->routes($router, new EntityTypeManager(new EventDispatcher()));
            } catch (\RuntimeException $exception) {
                if (!str_starts_with($exception->getMessage(), 'No binding registered for ')) {
                    throw $exception;
                }

                $containerOnly[] = $fqcn;
                continue;
            }

            foreach ($router->getRouteCollection() as $name => $route) {
                $collected[$name] = $route->getPath();
            }
        }

        return ['routes' => $collected, 'containerOnly' => $containerOnly];
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
     * Literal source scan for the same `/mcp` path / `mcp.` name predicate
     * {@see mcpRoutesIn()} applies to executed routes — used only for a
     * provider {@see routesFor()} could not execute. Route paths and names
     * are added throughout this codebase as literal PHP strings (never built
     * from variables), so a textual scan is a reliable stand-in for execution
     * here specifically.
     *
     * @param class-string $fqcn
     *
     * @return list<string> matched literal snippets, empty when none found
     */
    private function mcpSignatureInSource(string $fqcn): array
    {
        $reflection = new \ReflectionClass($fqcn);
        $file = $reflection->getFileName();
        self::assertIsString($file, sprintf('%s has no source file to scan.', $fqcn));

        $source = file_get_contents($file);
        self::assertIsString($source);

        $hits = [];
        if (preg_match_all('/[\'"](\/mcp[^\'"]*)[\'"]/', $source, $matches) > 0) {
            $hits = [...$hits, ...$matches[1]];
        }
        if (preg_match_all('/[\'"](mcp\.[^\'"]*)[\'"]/', $source, $matches) > 0) {
            $hits = [...$hits, ...$matches[1]];
        }

        return $hits;
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
    private function requireClosure(array $seeds, ?string $root = null): array
    {
        $root ??= $this->root();

        $seen = [];
        $queue = $seeds;
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name]) || !str_starts_with($name, 'waaseyaa/')) {
                continue;
            }
            $seen[$name] = true;
            $dir = $root . '/packages/' . substr($name, strlen('waaseyaa/'));
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

    /** @param array<string, mixed> $manifest */
    private function writeManifest(TemporaryDirectory $temporary, string $relative, array $manifest): void
    {
        $temporary->write($relative, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
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
