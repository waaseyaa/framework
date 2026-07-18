<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityExecutionBoundary;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\Capability\PrivilegedFieldReadCapability;
use Waaseyaa\Access\Capability\QueryFieldOperation;
use Waaseyaa\Access\Context\AccountFieldReadScope;

final class FieldReadAccessContractsTest extends TestCase
{
    #[Test]
    public function authorization_principal_is_an_immutable_account_snapshot(): void
    {
        $principal = new AuthorizationPrincipal(
            accountId: 12,
            authenticated: true,
            roles: ['member'],
            permissions: ['view profiles'],
            claimsGeneration: 'claims-7',
            tenantId: 'community-a',
            communityId: 'nation-a',
        );

        self::assertSame(12, $principal->id());
        self::assertTrue($principal->isAuthenticated());
        self::assertTrue($principal->hasPermission('view profiles'));
        self::assertSame('claims-7', $principal->claimsGeneration());
        self::assertSame('community-a', $principal->tenantId());
        self::assertSame('nation-a', $principal->communityId());
    }

    #[Test]
    public function administrator_role_preserves_the_framework_account_contract(): void
    {
        $principal = new AuthorizationPrincipal(1, true, ['administrator'], [], 'claims-1');

        self::assertTrue($principal->hasPermission('any reviewed route permission'));
    }

    #[Test]
    public function account_scope_is_nested_and_restored_in_finally(): void
    {
        $scope = new AccountFieldReadScope();
        $outer = new AuthorizationPrincipal(1, true, [], [], '1');
        $inner = new AuthorizationPrincipal(2, true, [], [], '1');

        $scope->run($outer, function () use ($scope, $outer, $inner): void {
            self::assertSame($outer, $scope->current());
            $outerContext = $scope->currentContext();
            self::assertNotNull($outerContext);
            try {
                $scope->run($inner, function () use ($scope, $outerContext): void {
                    self::assertNotSame($outerContext, $scope->currentContext());
                    throw new \RuntimeException('stop');
                });
            } catch (\RuntimeException) {
            }
            self::assertSame($outer, $scope->current());
            self::assertSame($outerContext, $scope->currentContext());
        });

        self::assertNull($scope->current());
        self::assertNull($scope->currentContext());
    }

    #[Test]
    public function child_fibers_do_not_inherit_account_authority(): void
    {
        $scope = new AccountFieldReadScope();
        $principal = new AuthorizationPrincipal(1, true, [], [], '1');

        $scope->run($principal, function () use ($scope): void {
            $fiber = new \Fiber(static fn (): mixed => $scope->current());
            self::assertNull($fiber->start());
        });
    }

    #[Test]
    public function capability_declarations_are_exact_and_separate_value_from_query_authority(): void
    {
        $declaration = new CapabilityDeclaration(
            issuer: 'migration.people',
            reason: CapabilityReason::MigrationImport,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['mail'],
            queryFields: ['status'],
            queryOperations: [QueryFieldOperation::Predicate],
            tenantId: 'community-a',
            communityId: 'nation-a',
            actorSemantics: [CapabilityActorSemantics::Account],
            maxTtlSeconds: 300,
            justification: 'Import the reviewed people manifest.',
        );

        self::assertSame(['mail'], $declaration->fields);
        self::assertSame(['user'], $declaration->bundles);
        self::assertSame(['status'], $declaration->queryFields);
        self::assertSame([QueryFieldOperation::Predicate], $declaration->queryOperations);
    }

    #[Test]
    public function registry_authority_is_weakmap_membership_not_handle_contents(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->register(new CapabilityDeclaration(
            issuer: 'admin.people',
            reason: CapabilityReason::AdminTooling,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['mail'],
            tenantId: 'tenant-a',
            communityId: 'community-a',
            actorSemantics: [CapabilityActorSemantics::Account],
            maxTtlSeconds: 60,
            justification: 'Reviewed account administration.',
        ));
        $context = new CapabilityIssueContext(
            executionBoundary: 'request-1',
            actorSemantics: CapabilityActorSemantics::Account,
            actorId: 7,
            tenantId: 'tenant-a',
            communityId: 'community-a',
            expiresAt: new \DateTimeImmutable('+30 seconds'),
            classificationGeneration: 'class-3',
            policyGeneration: 'policy-4',
        );

        $boundary = $registry->openBoundary('request-1');
        $issued = $registry->issueValueRead('admin.people', $context, $boundary);
        $forged = new PrivilegedFieldReadCapability();

        $authorization = $registry->authorizationFor($issued, $boundary);
        self::assertNotNull($authorization);
        self::assertSame(['user'], $authorization->declaration->bundles);
        self::assertSame('tenant-a', $authorization->context->tenantId);
        self::assertSame('community-a', $authorization->context->communityId);
        self::assertSame(7, $authorization->context->actorId);
        self::assertSame('class-3', $authorization->context->classificationGeneration);
        self::assertSame('policy-4', $authorization->context->policyGeneration);
        self::assertNull($registry->authorizationFor($forged, $boundary));
        self::assertFalse(method_exists($issued, 'registryNonce'));
        $this->expectException(\LogicException::class);
        serialize($issued);
    }

    #[Test]
    public function registry_membership_expires_with_its_issue_context(): void
    {
        $now = new \DateTimeImmutable('2030-01-01T00:00:00+00:00');
        $registry = new InMemoryCapabilityRegistry(static function () use (&$now): \DateTimeImmutable {
            return $now;
        });
        $registry->register(new CapabilityDeclaration(
            issuer: 'migration.people',
            reason: CapabilityReason::MigrationImport,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['mail'],
            tenantId: 'tenant-a',
            communityId: 'community-a',
            actorSemantics: [CapabilityActorSemantics::System],
            maxTtlSeconds: 60,
            justification: 'Import the reviewed people manifest.',
        ));
        $boundary = $registry->openBoundary('worker-message-1');
        $issued = $registry->issueValueRead('migration.people', new CapabilityIssueContext(
            executionBoundary: 'worker-message-1',
            actorSemantics: CapabilityActorSemantics::System,
            actorId: 'migration-worker',
            tenantId: 'tenant-a',
            communityId: 'community-a',
            expiresAt: $now->modify('+30 seconds'),
            classificationGeneration: 'class-3',
            policyGeneration: 'policy-4',
        ), $boundary);

        self::assertNotNull($registry->authorizationFor($issued, $boundary));
        $now = $now->modify('+31 seconds');
        self::assertNull($registry->authorizationFor($issued, $boundary));
    }

    #[Test]
    public function registry_requires_the_live_non_forgeable_execution_boundary_at_use(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->register(new CapabilityDeclaration(
            issuer: 'admin.people',
            reason: CapabilityReason::AdminTooling,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['mail'],
            actorSemantics: [CapabilityActorSemantics::Account],
            justification: 'Reviewed account administration.',
        ));
        $boundary = $registry->openBoundary('request-1');
        $issued = $registry->issueValueRead('admin.people', new CapabilityIssueContext(
            executionBoundary: 'request-1',
            actorSemantics: CapabilityActorSemantics::Account,
            actorId: 7,
            tenantId: null,
            communityId: null,
            expiresAt: new \DateTimeImmutable('+30 seconds'),
            classificationGeneration: 'class-1',
            policyGeneration: 'policy-1',
        ), $boundary);

        self::assertNotNull($registry->authorizationFor($issued, $boundary));
        self::assertNull($registry->authorizationFor($issued, $registry->openBoundary('request-1')));
        self::assertNull($registry->authorizationFor($issued, new CapabilityExecutionBoundary('request-1')));

        $registry->revokeBoundary($boundary);

        self::assertNull($registry->authorizationFor($issued, $boundary));
    }

    #[Test]
    public function registry_rejects_a_boundary_shaped_object_at_issuance(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->register(new CapabilityDeclaration(
            issuer: 'admin.people',
            reason: CapabilityReason::AdminTooling,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['mail'],
            actorSemantics: [CapabilityActorSemantics::Account],
            justification: 'Reviewed account administration.',
        ));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('matching live execution boundary');
        $registry->issueValueRead('admin.people', new CapabilityIssueContext(
            executionBoundary: 'request-1',
            actorSemantics: CapabilityActorSemantics::Account,
            actorId: 7,
            tenantId: null,
            communityId: null,
            expiresAt: new \DateTimeImmutable('+30 seconds'),
            classificationGeneration: 'class-1',
            policyGeneration: 'policy-1',
        ), new CapabilityExecutionBoundary('request-1'));
    }

    #[Test]
    public function declaration_must_explicitly_allow_binding_request_tenant_and_community(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->register(new CapabilityDeclaration(
            issuer: 'http.identity-bootstrap',
            reason: CapabilityReason::SessionBootstrap,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['roles'],
            actorSemantics: [CapabilityActorSemantics::NoActingContext],
            justification: 'Bind the resolved HTTP data scope.',
            bindTenantFromContext: true,
            bindCommunityFromContext: true,
        ));

        $boundary = $registry->openBoundary('request-scoped');
        $issued = $registry->issueValueRead('http.identity-bootstrap', new CapabilityIssueContext(
            executionBoundary: 'request-scoped',
            actorSemantics: CapabilityActorSemantics::NoActingContext,
            actorId: null,
            tenantId: 'tenant-a',
            communityId: 'community-a',
            expiresAt: new \DateTimeImmutable('+30 seconds'),
            classificationGeneration: 'class-1',
            policyGeneration: 'policy-1',
        ), $boundary);

        self::assertSame('community-a', $registry->authorizationFor($issued, $boundary)?->context->communityId);
    }
}
