<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Api\Http\Router\McpApprovalApiRouter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Route table + wiring contract of the MCP approval decision surface
 * (#2177 F1 C1b): authenticated session-only JSON admin endpoints over the
 * durable approval store.
 */
#[CoversClass(ApiServiceProvider::class)]
final class ApiServiceProviderApprovalRoutesTest extends TestCase
{
    private WaaseyaaRouter $router;

    protected function setUp(): void
    {
        $this->router = new WaaseyaaRouter();
        (new ApiServiceProvider())->routes($this->router, new EntityTypeManager(new EventDispatcher()));
    }

    #[Test]
    public function registers_the_pending_queue_route_with_session_and_view_permission(): void
    {
        $route = $this->router->getRouteCollection()->get('api.mcp.approvals.index');

        self::assertNotNull($route, 'api.mcp.approvals.index must be registered.');
        self::assertSame('/api/mcp/approvals', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
        self::assertTrue((bool) $route->getOption('_authenticated'));
        self::assertSame(['waaseyaa_uid'], $route->getOption('_session'), 'A real login session is required — bearer-only must not pass.');
        self::assertSame('mcp.approval.view', $route->getOption('_permission'));
    }

    #[Test]
    public function registers_the_decision_route_with_csrf_session_and_decide_permission(): void
    {
        $route = $this->router->getRouteCollection()->get('api.mcp.approvals.decision');

        self::assertNotNull($route, 'api.mcp.approvals.decision must be registered.');
        self::assertSame('/api/mcp/approvals/{id}/decision', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
        self::assertTrue((bool) $route->getOption('_authenticated'));
        self::assertSame(['waaseyaa_uid'], $route->getOption('_session'));
        self::assertSame('mcp.approval.decide', $route->getOption('_permission'));
        self::assertTrue($route->getOption('_csrf'), 'The decision route must opt IN to CSRF validation despite its JSON content type.');
    }

    #[Test]
    public function view_and_decide_are_distinct_permissions(): void
    {
        $collection = $this->router->getRouteCollection();

        self::assertNotSame(
            $collection->get('api.mcp.approvals.index')?->getOption('_permission'),
            $collection->get('api.mcp.approvals.decision')?->getOption('_permission'),
            'A view-only audience must not implicitly hold decide.',
        );
    }

    #[Test]
    public function router_chain_contains_the_approval_router(): void
    {
        $provider = $this->providerWithConfig([]);

        self::assertNotNull($this->approvalRouter($provider));
    }

    #[Test]
    public function non_bool_allow_self_approval_values_fail_boot_without_echoing_the_value(): void
    {
        // STRICT boolean means PHP bool only: even boolean-shaped strings and
        // integers are refused — a security override must be stated, not
        // coerced. The exception names the key and the value's TYPE only.
        foreach (['perhaps', 'true', 'false', '1', '0', 'on', 'off', 'yes', 1, 0, null, [], 1.0, 'SECRET-VALUE-hunter2'] as $value) {
            $provider = $this->providerWithConfig([
                'mcp' => ['write_tier' => ['approval' => ['allow_self_approval' => $value]]],
            ]);

            try {
                $this->approvalRouter($provider);
                self::fail('A non-bool allow_self_approval value must throw: ' . var_export($value, true));
            } catch (ConfigException $e) {
                self::assertStringContainsString('mcp.write_tier.approval.allow_self_approval', $e->getMessage());
                // The message names the TYPE only. Non-echo is asserted with
                // distinctive values — boolean-shaped words like "true"
                // legitimately appear in the static guidance text.
                if (\in_array($value, ['perhaps', 'SECRET-VALUE-hunter2'], true)) {
                    self::assertStringNotContainsString($value, $e->getMessage(), 'The malformed value itself must not be echoed.');
                }
            }
        }
    }

    #[Test]
    public function only_php_bool_allow_self_approval_values_are_accepted(): void
    {
        foreach ([true, false] as $value) {
            $provider = $this->providerWithConfig([
                'mcp' => ['write_tier' => ['approval' => ['allow_self_approval' => $value]]],
            ]);

            self::assertNotNull($this->approvalRouter($provider), var_export($value, true));
        }
    }

    #[Test]
    public function an_absent_allow_self_approval_key_defaults_to_false(): void
    {
        self::assertNotNull($this->approvalRouter($this->providerWithConfig([])));
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $config */
    private function providerWithConfig(array $config): ApiServiceProvider
    {
        $provider = new ApiServiceProvider();
        $provider->setKernelContext('', $config, []);
        $provider->register();

        return $provider;
    }

    private function approvalRouter(ApiServiceProvider $provider): ?McpApprovalApiRouter
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $database = DBALDatabase::createSqlite(':memory:');
        $kernel = new HttpKernel(sys_get_temp_dir());
        (new \ReflectionProperty(AbstractKernel::class, 'entityTypeManager'))->setValue($kernel, $manager);
        (new \ReflectionProperty(HttpKernel::class, 'discoveryHandler'))->setValue(
            $kernel,
            new DiscoveryApiHandler($manager, $database),
        );

        foreach ($provider->httpDomainRouters($kernel) as $candidate) {
            if ($candidate instanceof McpApprovalApiRouter) {
                return $candidate;
            }
        }

        return null;
    }
}
