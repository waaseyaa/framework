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
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;

final class AuditedFieldReadTest extends TestCase
{
    #[Test]
    public function guarded_first_party_value_is_obtained_only_by_the_audited_reader_after_reservation(): void
    {
        $events = [];
        $ledger = new class($events) implements StrictPrivilegedReadLedgerInterface {
            /** @param list<string> $events */
            public function __construct(public array &$events) {}
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                $this->events[] = 'reserve';

                return new PrivilegedReadReceipt('receipt-guarded');
            }
            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void
            {
                $this->events[] = 'finalize:'.$outcome->value;
            }
        };
        $entity = new class(['id' => 12, 'mail' => 'guarded@example.test'], 'user', ['id' => 'id']) extends EntityBase {
            public function get(string $name): mixed
            {
                if ($name === 'mail') {
                    throw new FieldReadDenied('Simulated WP4 protected-field guard.');
                }

                return parent::get($name);
            }
        };
        try {
            $entity->get('mail');
            self::fail('An ordinary guarded read must deny.');
        } catch (FieldReadDenied) {
            self::assertSame([], $events);
        }

        $registry = new InMemoryCapabilityRegistry();
        $registry->register(new CapabilityDeclaration(
            issuer: 'migration.people',
            reason: CapabilityReason::MigrationImport,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['mail'],
            tenantId: 'tenant-a',
            communityId: 'community-a',
            actorSemantics: [CapabilityActorSemantics::System],
            justification: 'Reviewed people migration.',
        ));
        $boundary = $registry->openBoundary('migration-run-guarded');
        $capability = $registry->issueValueRead('migration.people', new CapabilityIssueContext(
            executionBoundary: 'migration-run-guarded',
            actorSemantics: CapabilityActorSemantics::System,
            actorId: 'migration-worker',
            tenantId: 'tenant-a',
            communityId: 'community-a',
            expiresAt: new \DateTimeImmutable('+30 seconds'),
            classificationGeneration: 'class-1',
            policyGeneration: 'policy-1',
        ), $boundary);

        self::assertSame(
            'guarded@example.test',
            (new AuditedFieldRead($registry, $ledger))->read($capability, $boundary, $entity, 'mail'),
        );
        self::assertSame(['reserve', 'finalize:succeeded'], $events);

        $registry->revokeBoundary($boundary);
        try {
            (new AuditedFieldRead($registry, $ledger))->read($capability, $boundary, $entity, 'mail');
            self::fail('A handle retained outside its live execution boundary must deny.');
        } catch (FieldReadDenied) {
            self::assertSame(['reserve', 'finalize:succeeded'], $events);
        }
    }

    #[Test]
    public function reservation_is_durable_before_the_value_is_obtained_and_success_is_finalized(): void
    {
        $events = [];
        $ledger = new class($events) implements StrictPrivilegedReadLedgerInterface {
            /** @param list<string> $events */
            public function __construct(public array &$events) {}

            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                $this->events[] = 'reserve:'.implode(',', $descriptor->fields);

                return new PrivilegedReadReceipt('receipt-1');
            }

            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void
            {
                $this->events[] = 'finalize:'.$outcome->value;
            }
        };
        $entity = new class($events) implements EntityInterface {
            /** @param list<string> $events */
            public function __construct(private array &$events) {}
            public function id(): int|string|null { return 12; }
            public function uuid(): string { return 'user-12'; }
            public function label(): string { return 'Member'; }
            public function getEntityTypeId(): string { return 'user'; }
            public function bundle(): string { return 'user'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { $this->events[] = 'obtain:'.$name; return 'member@example.test'; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
        };
        $registry = new InMemoryCapabilityRegistry();
        $registry->register(new CapabilityDeclaration(
            issuer: 'migration.people',
            reason: CapabilityReason::MigrationImport,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['mail'],
            tenantId: 'tenant-a',
            communityId: 'community-a',
            actorSemantics: [CapabilityActorSemantics::System],
            justification: 'Reviewed people migration.',
        ));
        $boundary = $registry->openBoundary('migration-run-1');
        $capability = $registry->issueValueRead('migration.people', new CapabilityIssueContext(
            executionBoundary: 'migration-run-1',
            actorSemantics: CapabilityActorSemantics::System,
            actorId: 'migration-worker',
            tenantId: 'tenant-a',
            communityId: 'community-a',
            expiresAt: new \DateTimeImmutable('+30 seconds'),
            classificationGeneration: 'class-1',
            policyGeneration: 'policy-1',
        ), $boundary);

        $value = new AuditedFieldRead($registry, $ledger)->read($capability, $boundary, $entity, 'mail');

        self::assertSame('member@example.test', $value);
        self::assertSame(['reserve:mail', 'obtain:mail', 'finalize:succeeded'], $events);
    }
}
