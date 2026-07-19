<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Http;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Audit\AuditedFieldRead;

/** Explicit audited reader for the fixed download metadata set. @api */
final readonly class AuditedAttachmentDownloadMetadataReader implements AttachmentDownloadMetadataReaderInterface
{
    public const string ISSUER = 'attachment.authorized-download';

    public function __construct(
        private AuditedFieldRead $reader,
        private CapabilityRegistryInterface $capabilities,
        private string $classificationGeneration,
        private string $policyGeneration,
    ) {
        $this->capabilities->register(new CapabilityDeclaration(
            issuer: self::ISSUER,
            reason: CapabilityReason::AdminTooling,
            entityTypes: ['attachment'],
            bundles: ['attachment'],
            fields: ['storage_uri', 'content_type', 'filename'],
            actorSemantics: [CapabilityActorSemantics::Account],
            maxTtlSeconds: 30,
            justification: 'Read the exact file metadata after parent-delegated download authorization succeeds.',
        ));
    }

    public function read(Attachment $attachment, AccountInterface $account): AttachmentDownloadMetadata
    {
        $boundary = $this->capabilities->openBoundary('attachment-download:' . bin2hex(random_bytes(12)));
        try {
            $capability = $this->capabilities->issueValueRead(self::ISSUER, new CapabilityIssueContext(
                executionBoundary: $boundary->correlationId,
                actorSemantics: CapabilityActorSemantics::Account,
                actorId: $account->id(),
                tenantId: null,
                communityId: null,
                expiresAt: new \DateTimeImmutable('+30 seconds'),
                classificationGeneration: $this->classificationGeneration,
                policyGeneration: $this->policyGeneration,
            ), $boundary);
            $values = $this->reader->readMany(
                $capability,
                $boundary,
                $attachment,
                ['storage_uri', 'content_type', 'filename'],
                CapabilityReason::AdminTooling,
            );

            return new AttachmentDownloadMetadata(
                (string) $values['storage_uri'],
                (string) $values['content_type'],
                (string) $values['filename'],
            );
        } finally {
            $this->capabilities->revokeBoundary($boundary);
        }
    }
}
