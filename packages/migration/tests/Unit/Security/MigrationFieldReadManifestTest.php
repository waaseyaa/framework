<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Migration\Security\MigrationFieldReadCapabilityIssuer;
use Waaseyaa\Migration\Security\MigrationFieldReadManifest;

final class MigrationFieldReadManifestTest extends TestCase
{
    #[Test]
    public function manifestIssuesOnlyItsExactFieldsWithNoActingAccount(): void
    {
        $registry = new InMemoryCapabilityRegistry(static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-07-17T12:00:00Z'));
        $manifest = new MigrationFieldReadManifest(
            migrationId: 'members_import',
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['mail'],
            justification: 'Compare an imported identity record with its existing address.',
        );
        $issuer = new MigrationFieldReadCapabilityIssuer($registry, 'classifications-v2', 'policies-v4');
        $issuer->register($manifest);

        $boundary = $registry->openBoundary('migration-run-9');
        $capability = $issuer->issue($manifest, $boundary, new \DateTimeImmutable('2026-07-17T12:02:00Z'));
        $authorization = $registry->authorizationFor($capability, $boundary);

        self::assertNotNull($authorization);
        self::assertSame(['mail'], $authorization->declaration->fields);
        self::assertSame(CapabilityActorSemantics::NoActingContext, $authorization->context->actorSemantics);
        self::assertNull($authorization->context->actorId);
    }
}
