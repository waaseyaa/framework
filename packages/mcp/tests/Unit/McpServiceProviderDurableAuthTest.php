<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalBootstrapReaderInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Auth\Token\Bearer\BearerTokenStoreInterface;
use Waaseyaa\Auth\Token\Bearer\DatabaseBearerTokenStore;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\DurableBearerTokenAuth;
use Waaseyaa\Mcp\Auth\WriteTierAuthInterface;
use Waaseyaa\Mcp\AuthenticatedMcpEndpoint;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpServiceProvider;

/**
 * Write-tier default auth wiring (#2177 F3): with a durable token store and a
 * user repository available on the kernel-services bus, the framework default
 * is the durable {@see DurableBearerTokenAuth} — an application override still
 * wins, and with no store the tier stays the fail-closed empty
 * {@see BearerTokenAuth}.
 */
#[CoversClass(McpServiceProvider::class)]
final class McpServiceProviderDurableAuthTest extends TestCase
{
    /** Config that keeps this test about auth wiring, not audit wiring. */
    private const array CONFIG = [
        'mcp' => [
            'rate_limit' => ['max_requests' => 0],
            'write_tier' => ['durable_audit' => false, 'approval' => ['enabled' => false]],
        ],
    ];

    #[Test]
    public function the_write_tier_defaults_to_durable_token_auth_when_wireable(): void
    {
        $auth = $this->resolveWriteTierAuth(withStore: true, withUsers: true);

        self::assertInstanceOf(DurableBearerTokenAuth::class, $auth);
    }

    #[Test]
    public function without_a_token_store_the_tier_stays_the_fail_closed_empty_map(): void
    {
        $auth = $this->resolveWriteTierAuth(withStore: false, withUsers: true);

        self::assertInstanceOf(BearerTokenAuth::class, $auth);
        self::assertSame([], $auth->getTokens());
    }

    #[Test]
    public function without_a_user_repository_the_tier_stays_the_fail_closed_empty_map(): void
    {
        $auth = $this->resolveWriteTierAuth(withStore: true, withUsers: false);

        self::assertInstanceOf(BearerTokenAuth::class, $auth);
    }

    #[Test]
    public function an_application_bound_write_tier_auth_still_wins(): void
    {
        $override = new BearerTokenAuth([]);

        $auth = $this->resolveWriteTierAuth(withStore: true, withUsers: true, override: $override);

        self::assertSame($override, $auth);
    }

    private function resolveWriteTierAuth(
        bool $withStore,
        bool $withUsers,
        ?WriteTierAuthInterface $override = null,
    ): object {
        $userRepository = $this->createMock(EntityRepositoryInterface::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getRepository')->with('user')->willReturn($userRepository);

        $mcp = new McpServiceProvider();
        $app = new class($withStore, $withUsers ? $entityTypeManager : null, $override) extends ServiceProvider {
            public function __construct(
                private readonly bool $withStore,
                private readonly ?EntityTypeManagerInterface $entityTypeManager,
                private readonly ?WriteTierAuthInterface $override,
            ) {}

            public function register(): void
            {
                $this->singleton(
                    \Waaseyaa\AI\Tools\ToolRegistryInterface::class,
                    fn(): object => new \Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry(
                        new \Waaseyaa\Foundation\Discovery\PackageManifest(),
                        new class implements \Psr\Container\ContainerInterface {
                            public function get(string $id): mixed
                            {
                                throw new \RuntimeException('not used');
                            }

                            public function has(string $id): bool
                            {
                                return false;
                            }
                        },
                    ),
                );

                if ($this->withStore) {
                    $this->singleton(
                        BearerTokenStoreInterface::class,
                        static fn(): object => new DatabaseBearerTokenStore(DBALDatabase::createSqlite()),
                    );
                }
                $this->singleton(
                    AccountPrincipalFactoryInterface::class,
                    static fn(): object => new AccountPrincipalFactory(
                        new class implements AuthorizationPrincipalBootstrapReaderInterface {
                            public function fromEntity(\Waaseyaa\Entity\EntityInterface $account, ?string $tenantId = null, ?string $communityId = null): AuthorizationPrincipalInterface
                            {
                                return new AuthorizationPrincipal(
                                    accountId: (int) $account->id(),
                                    authenticated: true,
                                    roles: [],
                                    permissions: [],
                                    claimsGeneration: 'test-generation',
                                );
                            }
                        },
                    ),
                );

                if ($this->entityTypeManager !== null) {
                    $entityTypeManager = $this->entityTypeManager;
                    $this->singleton(EntityTypeManagerInterface::class, static fn(): object => $entityTypeManager);
                }
                if ($this->override !== null) {
                    $override = $this->override;
                    $this->singleton(WriteTierAuthInterface::class, static fn(): object => $override);
                }
            }
        };

        $providers = [$mcp, $app];
        $bus = new class(static fn(): array => $providers) implements KernelServicesInterface {
            public function __construct(private \Closure $providers) {}

            public function get(string $abstract): ?object
            {
                foreach (($this->providers)() as $provider) {
                    if (isset($provider->getBindings()[$abstract])) {
                        return $provider->resolve($abstract);
                    }
                }

                return null;
            }
        };
        foreach ($providers as $provider) {
            $provider->setKernelContext('', self::CONFIG, []);
            $provider->setKernelServices($bus);
        }
        foreach ($providers as $provider) {
            $provider->register();
        }

        $endpoint = $mcp->resolve(AuthenticatedMcpEndpoint::class);
        $inner = new \ReflectionProperty(AuthenticatedMcpEndpoint::class, 'inner')->getValue($endpoint);

        return new \ReflectionProperty(McpEndpoint::class, 'auth')->getValue($inner);
    }
}
