<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Capability\CapabilityAuthorization;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityExecutionBoundary;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\Capability\PrivilegedFieldReadCapability;
use Waaseyaa\Access\Capability\QueryFieldReadCapability;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Media\Http\AuditedMediaDownloadSourceReader;
use Waaseyaa\Media\Media;

final class AuditedMediaDownloadSourceReaderTest extends TestCase
{
    #[Test]
    public function tenant_and_community_scoped_principal_reads_source_and_the_boundary_is_revoked(): void
    {
        $registry = new RecordingCapabilityRegistry();
        $reader = new AuditedMediaDownloadSourceReader(
            new AuditedFieldRead($registry, $this->successfulLedger()),
            $registry,
            'classification-v1',
            'policy-v1',
        );
        $principal = new AuthorizationPrincipal(
            7,
            true,
            ['band_member'],
            ['access media'],
            'claims-v1',
            tenantId: 'tenant-a',
            communityId: 'community-a',
        );

        self::assertSame('public://teaching.txt', $reader->sourceUri($this->media(), $principal));
        self::assertSame('tenant-a', $registry->issuedContext?->tenantId);
        self::assertSame('community-a', $registry->issuedContext?->communityId);
        $registry->assertLastCapabilityRevoked();
    }

    #[Test]
    public function failed_audited_read_revokes_the_boundary(): void
    {
        $registry = new RecordingCapabilityRegistry();
        $ledger = new class implements StrictPrivilegedReadLedgerInterface {
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                throw new \RuntimeException('Audit ledger unavailable.');
            }

            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
        };
        $reader = new AuditedMediaDownloadSourceReader(
            new AuditedFieldRead($registry, $ledger),
            $registry,
            'classification-v1',
            'policy-v1',
        );

        try {
            $reader->sourceUri(
                $this->media(),
                new AuthorizationPrincipal(7, true, ['band_member'], [], 'claims-v1'),
            );
            self::fail('The audit failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Audit ledger unavailable.', $exception->getMessage());
        }

        $registry->assertLastCapabilityRevoked();
    }

    private function media(): Media
    {
        return new Media([
            'mid' => 10,
            'bundle' => 'document',
            'source_uri' => 'public://teaching.txt',
            'filename' => 'teaching.txt',
            'mime_type' => 'text/plain',
            'status' => 1,
            'uid' => 99,
        ]);
    }

    private function successfulLedger(): StrictPrivilegedReadLedgerInterface
    {
        return new class implements StrictPrivilegedReadLedgerInterface {
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                return new PrivilegedReadReceipt('media-source-read');
            }

            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
        };
    }
}

final class RecordingCapabilityRegistry implements CapabilityRegistryInterface
{
    private InMemoryCapabilityRegistry $inner;
    public ?CapabilityIssueContext $issuedContext = null;
    private ?PrivilegedFieldReadCapability $issuedCapability = null;
    private ?CapabilityExecutionBoundary $issuedBoundary = null;

    public function __construct()
    {
        $this->inner = new InMemoryCapabilityRegistry();
    }

    public function register(CapabilityDeclaration $declaration): void
    {
        $this->inner->register($declaration);
    }

    public function openBoundary(string $correlationId): CapabilityExecutionBoundary
    {
        return $this->inner->openBoundary($correlationId);
    }

    public function issueValueRead(string $issuer, CapabilityIssueContext $context, CapabilityExecutionBoundary $boundary): PrivilegedFieldReadCapability
    {
        $this->issuedContext = $context;
        $this->issuedBoundary = $boundary;
        $this->issuedCapability = $this->inner->issueValueRead($issuer, $context, $boundary);

        return $this->issuedCapability;
    }

    public function issueQueryRead(string $issuer, CapabilityIssueContext $context, CapabilityExecutionBoundary $boundary): QueryFieldReadCapability
    {
        return $this->inner->issueQueryRead($issuer, $context, $boundary);
    }

    public function authorizationFor(PrivilegedFieldReadCapability|QueryFieldReadCapability $capability, CapabilityExecutionBoundary $boundary): ?CapabilityAuthorization
    {
        return $this->inner->authorizationFor($capability, $boundary);
    }

    public function revokeBoundary(CapabilityExecutionBoundary $boundary): void
    {
        $this->inner->revokeBoundary($boundary);
    }

    public function assertLastCapabilityRevoked(): void
    {
        TestCase::assertNotNull($this->issuedCapability);
        TestCase::assertNotNull($this->issuedBoundary);
        TestCase::assertNull($this->inner->authorizationFor($this->issuedCapability, $this->issuedBoundary));
    }
}
