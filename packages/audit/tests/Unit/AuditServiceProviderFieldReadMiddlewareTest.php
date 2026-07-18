<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Middleware\FieldReadContextMiddleware;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Audit\AuditServiceProvider;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\User;

final class AuditServiceProviderFieldReadMiddlewareTest extends TestCase
{
    #[Test]
    public function provider_reuses_the_kernel_owned_scope_by_identity(): void
    {
        $database = DBALDatabase::createSqlite();
        $scope = new AccountFieldReadScope();
        $provider = new AuditServiceProvider();
        $provider->setKernelServices(new class($database, $scope) implements KernelServicesInterface {
            public function __construct(
                private readonly DatabaseInterface $database,
                private readonly AccountFieldReadScopeInterface $scope,
            ) {}
            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    DatabaseInterface::class => $this->database,
                    AccountFieldReadScopeInterface::class => $this->scope,
                    default => null,
                };
            }
        });
        $provider->register();

        self::assertSame($scope, $provider->resolve(AccountFieldReadScopeInterface::class));
    }

    #[Test]
    public function provider_contributes_the_production_context_middleware_and_strict_ledger(): void
    {
        $database = DBALDatabase::createSqlite();
        (new AuditEventSchemaHandler($database))->ensureSchema();
        $dispatcher = new EventDispatcher();
        $manager = new EntityTypeManager($dispatcher);
        $provider = new AuditServiceProvider();
        $provider->setKernelServices(new class($database) implements KernelServicesInterface {
            public function __construct(private readonly DatabaseInterface $database) {}
            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->database : null;
            }
        });
        $provider->register();

        $middleware = $provider->middleware($manager);

        self::assertInstanceOf(FieldReadContextMiddleware::class, $middleware[0]);
        self::assertInstanceOf(StrictPrivilegedReadLedgerInterface::class, $provider->resolve(StrictPrivilegedReadLedgerInterface::class));
        self::assertInstanceOf(UserInternalFieldReaderInterface::class, $provider->resolve(UserInternalFieldReaderInterface::class));
        self::assertInstanceOf(UserIdentityLookupInterface::class, $provider->resolve(UserIdentityLookupInterface::class));
        self::assertInstanceOf(AccountPrincipalFactoryInterface::class, $provider->resolve(AccountPrincipalFactoryInterface::class));
    }

    #[Test]
    public function entity_account_claims_are_strictly_reserved_before_the_principal_is_installed(): void
    {
        $database = DBALDatabase::createSqlite();
        (new AuditEventSchemaHandler($database))->ensureSchema();
        $provider = new AuditServiceProvider();
        $provider->setKernelServices(new class($database) implements KernelServicesInterface {
            public function __construct(private readonly DatabaseInterface $database) {}
            public function get(string $abstract): ?object { return $abstract === DatabaseInterface::class ? $this->database : null; }
        });
        $provider->register();
        $middleware = $provider->middleware(new EntityTypeManager(new EventDispatcher()))[0];
        $request = Request::create('/members');
        $request->attributes->set('tenant_id', 'tenant-a');
        $request->attributes->set('community_id', 'community-a');
        $request->attributes->set('_account', new User([
            'uid' => 42,
            'name' => 'Member',
            'roles' => ['member'],
            'permissions' => ['view members'],
            'status' => 1,
        ]));
        $handler = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                $principal = $request->attributes->get('_authorization_principal');
                TestCase::assertInstanceOf(AuthorizationPrincipalInterface::class, $principal);
                TestCase::assertSame(['member'], $principal->getRoles());
                TestCase::assertTrue($principal->hasPermission('view members'));
                TestCase::assertSame($request->attributes->get('tenant_id'), $principal->tenantId());
                TestCase::assertSame($request->attributes->get('community_id'), $principal->communityId());
                return new Response('ok');
            }
        };

        self::assertSame('ok', $middleware->process($request, $handler)->getContent());
        $second = Request::create('/members/second');
        $second->attributes->set('tenant_id', 'tenant-b');
        $second->attributes->set('community_id', 'community-b');
        $second->attributes->set('_account', new User([
            'uid' => 43,
            'name' => 'Second member',
            'roles' => ['member'],
            'permissions' => ['view members'],
            'status' => 1,
        ]));
        self::assertSame('ok', $middleware->process($second, $handler)->getContent());
        $rows = iterator_to_array($database->query('SELECT event_type, descriptor FROM privileged_read_ledger ORDER BY id'));
        self::assertCount(4, $rows);
        self::assertSame('reserved', $rows[0]['event_type']);
        self::assertStringContainsString('"fields":["roles","permissions","status"]', (string) $rows[0]['descriptor']);
        self::assertStringContainsString('"community_id":"community-a"', (string) $rows[0]['descriptor']);
        self::assertStringNotContainsString('view members', (string) $rows[0]['descriptor']);
    }

    #[Test]
    public function entity_backed_anonymous_principal_is_explicit_and_strictly_bootstrapped(): void
    {
        [$database, , $middleware] = $this->productionMiddleware();
        $request = Request::create('/anonymous');
        $request->attributes->set('_account', new User(['uid' => 0, 'roles' => ['anonymous'], 'permissions' => [], 'status' => 1]));
        $handler = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                $principal = $request->attributes->get('_authorization_principal');
                TestCase::assertInstanceOf(AuthorizationPrincipalInterface::class, $principal);
                TestCase::assertSame(0, $principal->id());
                TestCase::assertFalse($principal->isAuthenticated());
                return new Response('anonymous');
            }
        };

        self::assertSame('anonymous', $middleware->process($request, $handler)->getContent());
        self::assertCount(2, iterator_to_array($database->query('SELECT id FROM privileged_read_ledger')));
    }

    #[Test]
    public function downstream_exception_clears_the_production_principal_scope(): void
    {
        [, $provider, $middleware] = $this->productionMiddleware();
        $request = Request::create('/exception');
        $request->attributes->set('_account', new User(['uid' => 44, 'roles' => ['member'], 'permissions' => [], 'status' => 1]));
        $scope = $provider->resolve(AccountFieldReadScopeInterface::class);
        assert($scope instanceof AccountFieldReadScopeInterface);

        try {
            $middleware->process($request, new class implements HttpHandlerInterface {
                public function handle(Request $request): Response { throw new \RuntimeException('stop'); }
            });
            self::fail('Expected downstream exception.');
        } catch (\RuntimeException $e) {
            self::assertSame('stop', $e->getMessage());
        }
        self::assertNull($scope->current());
    }

    /** @return array{DBALDatabase, AuditServiceProvider, FieldReadContextMiddleware} */
    private function productionMiddleware(): array
    {
        $database = DBALDatabase::createSqlite();
        (new AuditEventSchemaHandler($database))->ensureSchema();
        $provider = new AuditServiceProvider();
        $provider->setKernelServices(new class($database) implements KernelServicesInterface {
            public function __construct(private readonly DatabaseInterface $database) {}
            public function get(string $abstract): ?object { return $abstract === DatabaseInterface::class ? $this->database : null; }
        });
        $provider->register();
        $middleware = $provider->middleware(new EntityTypeManager(new EventDispatcher()))[0];
        assert($middleware instanceof FieldReadContextMiddleware);
        return [$database, $provider, $middleware];
    }
}
