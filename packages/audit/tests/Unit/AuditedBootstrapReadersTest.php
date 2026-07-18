<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Audit\Bootstrap\CredentialBootstrapReader;
use Waaseyaa\Audit\Bootstrap\IdentityBootstrapReader;
use Waaseyaa\Audit\Bootstrap\SessionBootstrapReader;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Entity\EntityInterface;

final class AuditedBootstrapReadersTest extends TestCase
{
    #[Test]
    public function multi_field_reader_reserves_once_before_any_value_and_records_only_names(): void
    {
        [$registry, $capability, $boundary] = $this->capability(CapabilityReason::SessionBootstrap, ['roles', 'permissions', 'status']);
        $events = [];
        $descriptors = [];
        $reader = new AuditedFieldRead($registry, $this->ledger($events, $descriptors));
        $entity = $this->entity($events, ['roles' => ['member'], 'permissions' => ['view'], 'status' => 1]);

        $values = $reader->readMany($capability, $boundary, $entity, ['roles', 'permissions', 'status']);

        self::assertSame(['roles' => ['member'], 'permissions' => ['view'], 'status' => 1], $values);
        self::assertSame(['reserve', 'get:roles', 'get:permissions', 'get:status', 'finalize:succeeded'], $events);
        self::assertSame(['roles', 'permissions', 'status'], $descriptors[0]->fields);
        self::assertFalse(property_exists($descriptors[0], 'values'));
    }

    #[Test]
    public function credential_session_and_identity_readers_require_their_declared_reason(): void
    {
        [$credentialRegistry, $credentialCapability, $credentialBoundary] = $this->capability(CapabilityReason::CredentialVerification, ['password', 'two_factor_secret']);
        [$sessionRegistry, $sessionCapability, $sessionBoundary] = $this->capability(CapabilityReason::SessionBootstrap, ['roles', 'permissions', 'status']);
        $events = [];
        $descriptors = [];
        $entity = $this->entity($events, [
            'password' => 'hash-not-a-log-value',
            'two_factor_secret' => 'secret-not-a-log-value',
            'roles' => ['member'],
            'permissions' => ['view members'],
            'status' => 1,
        ]);

        $credentials = (new CredentialBootstrapReader(new AuditedFieldRead($credentialRegistry, $this->ledger($events, $descriptors))))
            ->read($credentialCapability, $credentialBoundary, $entity, ['password', 'two_factor_secret']);
        $claims = (new SessionBootstrapReader(new AuditedFieldRead($sessionRegistry, $this->ledger($events, $descriptors))))
            ->read($sessionCapability, $sessionBoundary, $entity, ['roles', 'permissions', 'status']);
        $principal = (new IdentityBootstrapReader(
            new SessionBootstrapReader(new AuditedFieldRead($sessionRegistry, $this->ledger($events, $descriptors))),
            $sessionRegistry,
            'bootstrap.session_bootstrap',
        ))->fromEntity($entity);

        self::assertSame('hash-not-a-log-value', $credentials['password']);
        self::assertSame(['member'], $claims['roles']);
        self::assertSame(['member'], $principal->getRoles());
        self::assertTrue($principal->hasPermission('view members'));
        foreach ($descriptors as $descriptor) {
            self::assertFalse(property_exists($descriptor, 'values'));
        }
    }

    /** @return array{InMemoryCapabilityRegistry, \Waaseyaa\Access\Capability\PrivilegedFieldReadCapability, \Waaseyaa\Access\Capability\CapabilityExecutionBoundary} */
    private function capability(CapabilityReason $reason, array $fields): array
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->register(new CapabilityDeclaration(
            issuer: 'bootstrap.'.$reason->value,
            reason: $reason,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: $fields,
            actorSemantics: [CapabilityActorSemantics::NoActingContext],
            justification: 'Closed framework identity bootstrap.',
        ));
        $boundary = $registry->openBoundary('request-1');
        $capability = $registry->issueValueRead('bootstrap.'.$reason->value, new CapabilityIssueContext(
            executionBoundary: 'request-1',
            actorSemantics: CapabilityActorSemantics::NoActingContext,
            actorId: null,
            tenantId: null,
            communityId: null,
            expiresAt: new \DateTimeImmutable('+30 seconds'),
            classificationGeneration: 'class-1',
            policyGeneration: 'policy-1',
        ), $boundary);

        return [$registry, $capability, $boundary];
    }

    private function entity(array &$events, array $values): EntityInterface
    {
        return new class($events, $values) implements EntityInterface {
            public function __construct(private array &$events, private readonly array $values) {}
            public function id(): int|string|null { return 12; }
            public function uuid(): string { return 'user-12'; }
            public function label(): string { return 'Member'; }
            public function getEntityTypeId(): string { return 'user'; }
            public function bundle(): string { return 'user'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { $this->events[] = 'get:'.$name; return $this->values[$name] ?? null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
        };
    }

    private function ledger(array &$events, array &$descriptors): StrictPrivilegedReadLedgerInterface
    {
        return new class($events, $descriptors) implements StrictPrivilegedReadLedgerInterface {
            public function __construct(private array &$events, private array &$descriptors) {}
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                $this->events[] = 'reserve';
                $this->descriptors[] = $descriptor;
                return new PrivilegedReadReceipt('receipt-'.count($this->descriptors));
            }
            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void
            {
                $this->events[] = 'finalize:'.$outcome->value;
            }
        };
    }
}
