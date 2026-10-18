<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Policy\ContentAdminAccessPolicy;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

#[CoversClass(GenericAdminSurfaceHost::class)]
final class GenericAdminSurfaceHostAdmissionTest extends TestCase
{
    /** @return iterable<string, array{list<string>, ?string, bool}> */
    public static function admissionCases(): iterable
    {
        yield 'narrow entry grant' => [['access administration'], null, true];
        yield 'content administrator' => [['administer content'], null, true];
        yield 'role name is not a grant' => [[], null, false];
        yield 'application content permissions alone' => [['manage events'], null, false];
        yield 'custom host grant' => [['enter application admin'], 'enter application admin', true];
        yield 'narrow stock grant cannot bypass custom host' => [['access administration'], 'enter application admin', false];
        yield 'broad stock grant cannot bypass custom host' => [['administer content'], 'enter application admin', false];
        yield 'explicit content requirement remains exact' => [['access administration'], 'administer content', false];
    }

    /** @param list<string> $permissions */
    #[Test]
    #[DataProvider('admissionCases')]
    public function admission_uses_permissions_and_preserves_explicit_host_requirements(array $permissions, ?string $required, bool $allowed): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $host = $required === null
            ? new GenericAdminSurfaceHost($manager)
            : new GenericAdminSurfaceHost($manager, adminPermission: $required);
        $principal = new AuthorizationPrincipal(42, true, ['event_administrator'], $permissions, 'test');
        $request = Request::create('/admin/_surface/session');
        $request->attributes->set('_account', $principal);
        $request->attributes->set('_authorization_principal', $principal);

        self::assertSame($allowed, $host->resolveSession($request) !== null);
    }

    #[Test]
    public function an_entry_grant_does_not_grant_content_administrator_operations(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $policy = new ContentAdminAccessPolicy($manager);
        $principal = new AuthorizationPrincipal(42, true, ['event_administrator'], ['access administration'], 'test');
        $entity = $this->createStub(EntityInterface::class);
        foreach (['view', 'update', 'delete'] as $operation) {
            self::assertFalse($policy->access($entity, $operation, $principal)->isAllowed());
        }
        self::assertFalse($policy->createAccess('event', 'event', $principal)->isAllowed());
    }

    #[Test]
    public function a_request_without_an_account_cannot_enter(): void
    {
        $host = new GenericAdminSurfaceHost($this->createStub(EntityTypeManagerInterface::class));
        self::assertNull($host->resolveSession(Request::create('/admin/_surface/session')));
    }

    #[Test]
    public function an_unauthenticated_principal_with_an_entry_claim_is_refused(): void
    {
        $host = new GenericAdminSurfaceHost($this->createStub(EntityTypeManagerInterface::class));
        $request = Request::create('/admin/_surface/session');
        $request->attributes->set('_account', new AuthorizationPrincipal(0, false, [], ['access administration'], 'test'));
        self::assertNull($host->resolveSession($request));
    }
}
