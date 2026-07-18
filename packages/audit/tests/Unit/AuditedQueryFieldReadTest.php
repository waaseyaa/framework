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
use Waaseyaa\Access\Capability\QueryFieldOperation;
use Waaseyaa\Access\Query\QueryFieldReadRequest;
use Waaseyaa\Audit\AuditedQueryFieldRead;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;

final class AuditedQueryFieldReadTest extends TestCase
{
    #[Test]
    public function exact_query_shape_is_reserved_without_predicate_values_and_explicitly_finalized(): void
    {
        $registry = $this->registry();
        [$capability, $boundary] = $this->capability($registry);
        $events = [];
        $descriptor = null;
        $ledger = new class($events, $descriptor) implements StrictPrivilegedReadLedgerInterface {
            public function __construct(private array &$events, private ?PrivilegedReadDescriptor &$descriptor) {}
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                $this->events[] = 'reserve';
                $this->descriptor = $descriptor;
                return new PrivilegedReadReceipt('query-1');
            }
            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void
            {
                $this->events[] = 'finalize:'.$outcome->value;
            }
        };
        $request = QueryFieldReadRequest::fromShape(
            entityTypeId: 'user',
            bundles: ['user'],
            fields: ['mail'],
            operations: [QueryFieldOperation::Predicate, QueryFieldOperation::Count],
            normalizedShape: ['mail' => ['predicate' => 'equals', 'parameter' => '?']],
        );

        $reservation = (new AuditedQueryFieldRead($registry, $ledger))->reserve($capability, $boundary, $request);
        $events[] = 'execute';
        $reservation->succeeded();

        self::assertSame(['reserve', 'execute', 'finalize:succeeded'], $events);
        self::assertNotNull($descriptor);
        self::assertSame($request->fingerprint, $descriptor->queryFingerprint);
        self::assertStringNotContainsString('member@example.test', json_encode($descriptor, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function undeclared_field_or_operation_is_denied_before_reservation(): void
    {
        $registry = $this->registry();
        $ledger = $this->createMock(StrictPrivilegedReadLedgerInterface::class);
        $ledger->expects(self::never())->method('reserve');
        $request = QueryFieldReadRequest::fromShape(
            entityTypeId: 'user',
            bundles: ['user'],
            fields: ['mail'],
            operations: [QueryFieldOperation::Sort],
            normalizedShape: ['sort' => ['mail']],
        );

        $this->expectException(FieldReadDenied::class);
        [$capability, $boundary] = $this->capability($registry);
        (new AuditedQueryFieldRead($registry, $ledger))->reserve($capability, $boundary, $request);
    }

    private function registry(): InMemoryCapabilityRegistry
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->register(new CapabilityDeclaration(
            issuer: 'admin.member-query',
            reason: CapabilityReason::AdminTooling,
            entityTypes: ['user'],
            bundles: ['user'],
            queryFields: ['mail'],
            queryOperations: [QueryFieldOperation::Predicate, QueryFieldOperation::Count],
            actorSemantics: [CapabilityActorSemantics::Account],
            justification: 'Reviewed member administration query.',
        ));
        return $registry;
    }

    /** @return array{\Waaseyaa\Access\Capability\QueryFieldReadCapability, \Waaseyaa\Access\Capability\CapabilityExecutionBoundary} */
    private function capability(InMemoryCapabilityRegistry $registry): array
    {
        $boundary = $registry->openBoundary('request-1');
        $capability = $registry->issueQueryRead('admin.member-query', new CapabilityIssueContext(
            executionBoundary: 'request-1',
            actorSemantics: CapabilityActorSemantics::Account,
            actorId: 7,
            tenantId: null,
            communityId: null,
            expiresAt: new \DateTimeImmutable('+30 seconds'),
            classificationGeneration: 'class-1',
            policyGeneration: 'policy-1',
        ), $boundary);

        return [$capability, $boundary];
    }
}
