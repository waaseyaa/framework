<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\AdminSurface\Host\AuditedAdminPublicationFieldReader;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Contract\BatchStrictPrivilegedReadLedgerInterface;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Node\Node;

final class AuditedAdminPublicationFieldReaderTest extends TestCase
{
    #[Test]
    public function reads_only_node_publication_fields_under_the_acting_principal(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $ledger = new AdminPublicationRecordingLedger();
        $reader = new AuditedAdminPublicationFieldReader(
            new AuditedFieldRead($registry, $ledger),
            $registry,
            'classification-v1',
            'policy-v1',
        );
        $node = new Node([
            'nid' => 17,
            'type' => 'article',
            'title' => 'Review me',
            'slug' => 'review-me',
            'workflow_state' => 'review',
            'status' => false,
        ]);
        $principal = new AuthorizationPrincipal(
            9,
            true,
            ['editor'],
            ['administer content'],
            'claims-v1',
            tenantId: 'tenant-a',
            communityId: 'community-a',
        );

        self::assertTrue($reader->projects($node, 'workflow_state'));
        self::assertTrue($reader->projects($node, 'status'));
        self::assertFalse($reader->projects($node, 'uid'));
        self::assertSame([
            'workflow_state' => 'review',
            'status' => false,
        ], $reader->read($node, $principal));

        self::assertNotNull($ledger->descriptor);
        self::assertSame(CapabilityReason::StrictAuditProjection, $ledger->descriptor->reason);
        self::assertSame(9, $ledger->descriptor->actorId);
        self::assertSame('tenant-a', $ledger->descriptor->tenantId);
        self::assertSame('community-a', $ledger->descriptor->communityId);
        self::assertSame(['workflow_state', 'status'], $ledger->descriptor->fields);
        self::assertSame(PrivilegedReadOutcome::Succeeded, $ledger->outcome);
        self::assertSame([1], $ledger->reservationBatchSizes);
        self::assertSame([1], $ledger->finalizationBatchSizes);
    }

    #[Test]
    public function batches_entity_scoped_publication_evidence_into_one_reservation_and_finalization(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $ledger = new AdminPublicationRecordingLedger();
        $reader = new AuditedAdminPublicationFieldReader(new AuditedFieldRead($registry, $ledger), $registry);
        $nodes = [
            new Node(['nid' => 17, 'type' => 'article', 'title' => 'One', 'workflow_state' => 'review', 'status' => false]),
            new Node(['nid' => 18, 'type' => 'article', 'title' => 'Two', 'workflow_state' => 'published', 'status' => true]),
        ];

        $values = $reader->readMany($nodes, new AuthorizationPrincipal(9, true, ['editor'], [], 'claims-v1'));

        self::assertSame([
            ['workflow_state' => 'review', 'status' => false],
            ['workflow_state' => 'published', 'status' => true],
        ], $values);
        self::assertSame([2], $ledger->reservationBatchSizes);
        self::assertSame([2], $ledger->finalizationBatchSizes);
        self::assertSame([17, 18], array_map(static fn(PrivilegedReadDescriptor $descriptor) => $descriptor->entityId, $ledger->descriptors));
    }

    #[Test]
    public function empty_and_unsupported_batches_require_no_capability_or_ledger_work(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $ledger = new AdminPublicationRecordingLedger();
        $reader = new AuditedAdminPublicationFieldReader(new AuditedFieldRead($registry, $ledger), $registry);
        $unsupported = $this->createStub(EntityInterface::class);
        $unsupported->method('getEntityTypeId')->willReturn('user');
        $principal = new AuthorizationPrincipal(9, true, ['editor'], [], 'claims-v1');

        self::assertSame([], $reader->readMany([], $principal));
        self::assertSame([[]], $reader->readMany([$unsupported], $principal));
        self::assertSame([], $ledger->reservationBatchSizes);
        self::assertSame([], $ledger->finalizationBatchSizes);
    }
}

final class AdminPublicationRecordingLedger implements BatchStrictPrivilegedReadLedgerInterface
{
    public ?PrivilegedReadDescriptor $descriptor = null;
    public ?PrivilegedReadOutcome $outcome = null;
    /** @var list<PrivilegedReadDescriptor> */
    public array $descriptors = [];
    /** @var list<int> */
    public array $reservationBatchSizes = [];
    /** @var list<int> */
    public array $finalizationBatchSizes = [];

    public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
    {
        $this->descriptor = $descriptor;

        return new PrivilegedReadReceipt('admin-publication-read');
    }

    public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void
    {
        $this->outcome = $outcome;
    }

    public function reserveMany(array $descriptors): array
    {
        $this->descriptors = $descriptors;
        $this->descriptor = $descriptors[0];
        $this->reservationBatchSizes[] = count($descriptors);

        return array_map(
            static fn(int $index): PrivilegedReadReceipt => new PrivilegedReadReceipt('admin-publication-read-' . $index),
            array_keys($descriptors),
        );
    }

    public function finalizeMany(array $receipts, PrivilegedReadOutcome $outcome): void
    {
        $this->outcome = $outcome;
        $this->finalizationBatchSizes[] = count($receipts);
    }
}
