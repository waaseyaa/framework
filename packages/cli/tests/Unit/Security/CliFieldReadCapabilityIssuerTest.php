<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\CLI\Security\CliFieldReadCapabilityDeclaration;
use Waaseyaa\CLI\Security\CliFieldReadCapabilityIssuer;

final class CliFieldReadCapabilityIssuerTest extends TestCase
{
    #[Test]
    public function maintenanceCommandIsIssuedWithoutAnAmbientPrincipalOrActor(): void
    {
        $registry = new InMemoryCapabilityRegistry(static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-07-17T12:00:00Z'));
        $declaration = new CliFieldReadCapabilityDeclaration(
            command: 'user:export',
            reason: CapabilityReason::MaintenanceCli,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['mail'],
            justification: 'Reviewed operator export of the selected identity field.',
        );
        $issuer = new CliFieldReadCapabilityIssuer($registry, 'classifications-v2', 'policies-v4');
        $issuer->register($declaration);

        $boundary = $registry->openBoundary('cli-run-17');
        $capability = $issuer->issue($declaration, $boundary, new \DateTimeImmutable('2026-07-17T12:01:00Z'));
        $authorization = $registry->authorizationFor($capability, $boundary);

        self::assertNotNull($authorization);
        self::assertSame(CapabilityActorSemantics::NoActingContext, $authorization->context->actorSemantics);
        self::assertNull($authorization->context->actorId);
        self::assertSame('cli:maintenance_cli:user:export', $authorization->declaration->issuer);
        self::assertSame(CapabilityReason::MaintenanceCli, $authorization->declaration->reason);
    }

    #[Test]
    public function adminToolingIsASeparateReviewedCliReason(): void
    {
        $declaration = new CliFieldReadCapabilityDeclaration(
            command: 'admin:inspect',
            reason: CapabilityReason::AdminTooling,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['mail'],
            justification: 'Reviewed administrator inspection command.',
        );

        self::assertSame('cli:admin_tooling:admin:inspect', $declaration->issuer());
        $this->expectException(\InvalidArgumentException::class);
        new CliFieldReadCapabilityDeclaration(
            command: 'queue:work',
            reason: CapabilityReason::SystemJob,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['mail'],
            justification: 'System jobs use queue declarations, not CLI authority.',
        );
    }
}
