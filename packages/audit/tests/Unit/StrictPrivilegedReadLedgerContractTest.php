<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\QueryFieldOperation;
use Waaseyaa\Audit\Contract\PrivilegedReadKind;

final class StrictPrivilegedReadLedgerContractTest extends TestCase
{
    #[Test]
    public function reservation_precedes_finalization_and_descriptors_never_contain_values(): void
    {
        $ledger = new class implements StrictPrivilegedReadLedgerInterface {
            /** @var list<string> */
            public array $calls = [];

            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                $this->calls[] = 'reserve:'.implode(',', $descriptor->fields);

                return new PrivilegedReadReceipt('receipt-1');
            }

            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void
            {
                $this->calls[] = 'finalize:'.$receipt->id.':'.$outcome->value;
            }
        };

        $descriptor = new PrivilegedReadDescriptor(
            kind: PrivilegedReadKind::Value,
            reason: CapabilityReason::MigrationImport,
            issuer: 'migration.people',
            actorSemantics: CapabilityActorSemantics::System,
            actorId: 'migration-runner',
            entityTypeId: 'user',
            entityId: '12',
            fields: ['mail'],
            bundles: ['user'],
            tenantId: 'community-a',
            communityId: 'nation-a',
            queryFingerprint: null,
            queryOperations: [],
            classificationGeneration: 'class-1',
            policyGeneration: 'policy-1',
            correlationId: 'run-1',
            callSite: 'PeopleMigration.php:42',
        );
        $receipt = $ledger->reserve($descriptor);
        $ledger->finalize($receipt, PrivilegedReadOutcome::Succeeded);

        self::assertSame(['reserve:mail', 'finalize:receipt-1:succeeded'], $ledger->calls);
        self::assertArrayNotHasKey('value', get_object_vars($descriptor));
    }

    #[Test]
    public function query_reservations_require_fingerprint_and_operations_without_predicate_values(): void
    {
        $descriptor = new PrivilegedReadDescriptor(
            kind: PrivilegedReadKind::Query,
            reason: CapabilityReason::AdminTooling,
            issuer: 'admin.people',
            actorSemantics: CapabilityActorSemantics::Account,
            actorId: 7,
            entityTypeId: 'user',
            entityId: null,
            fields: ['status'],
            bundles: ['user'],
            tenantId: 'tenant-a',
            communityId: 'community-a',
            queryFingerprint: 'sha256:query-plan',
            queryOperations: [QueryFieldOperation::Predicate, QueryFieldOperation::Count],
            classificationGeneration: 'class-2',
            policyGeneration: 'policy-2',
            correlationId: 'request-1',
            callSite: 'PeopleAdmin.php:42',
        );

        self::assertSame(PrivilegedReadKind::Query, $descriptor->kind);
        self::assertArrayNotHasKey('predicateValues', get_object_vars($descriptor));
        self::assertArrayNotHasKey('results', get_object_vars($descriptor));
    }

    #[Test]
    public function query_reservations_reject_missing_fingerprint(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PrivilegedReadDescriptor(
            kind: PrivilegedReadKind::Query,
            reason: CapabilityReason::AdminTooling,
            issuer: 'admin.people',
            actorSemantics: CapabilityActorSemantics::Account,
            actorId: 7,
            entityTypeId: 'user',
            entityId: null,
            fields: ['status'],
            bundles: ['user'],
            tenantId: 'tenant-a',
            communityId: 'community-a',
            queryFingerprint: null,
            queryOperations: [QueryFieldOperation::Predicate],
            classificationGeneration: 'class-2',
            policyGeneration: 'policy-2',
            correlationId: 'request-1',
            callSite: 'PeopleAdmin.php:42',
        );
    }
}
