<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Http;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Media\Media;

/** Audited source lookup after MediaDownloadRouter's entity-view authorization. @api */
final readonly class AuditedMediaDownloadSourceReader implements MediaDownloadSourceReaderInterface
{
    public const string ISSUER = 'media.authorized-download';

    public function __construct(
        private AuditedFieldRead $reader,
        private CapabilityRegistryInterface $capabilities,
        private string $classificationGeneration,
        private string $policyGeneration,
    ) {
        $this->capabilities->register(new CapabilityDeclaration(
            issuer: self::ISSUER,
            reason: CapabilityReason::StrictAuditProjection,
            entityTypes: ['media'],
            // Media bundles are application-extensible. The typed reader and
            // fixed Media argument keep this escape hatch to media.source_uri;
            // wildcard avoids coupling the framework to application bundles.
            bundles: ['media'],
            fields: ['source_uri'],
            actorSemantics: [CapabilityActorSemantics::Account],
            maxTtlSeconds: 30,
            justification: 'Resolve only media.source_uri after the immutable principal is authorized to view that media entity.',
            wildcard: true,
            bindTenantFromContext: true,
            bindCommunityFromContext: true,
        ));
    }

    public function sourceUri(Media $media, AccountInterface $account): string
    {
        $boundary = $this->capabilities->openBoundary('media-download:' . bin2hex(random_bytes(12)));
        try {
            $capability = $this->capabilities->issueValueRead(self::ISSUER, new CapabilityIssueContext(
                executionBoundary: $boundary->correlationId,
                actorSemantics: CapabilityActorSemantics::Account,
                actorId: $account->id(),
                tenantId: $account instanceof \Waaseyaa\Access\AuthorizationPrincipalInterface ? $account->tenantId() : null,
                communityId: $account instanceof \Waaseyaa\Access\AuthorizationPrincipalInterface ? $account->communityId() : null,
                expiresAt: new \DateTimeImmutable('+30 seconds'),
                classificationGeneration: $this->classificationGeneration,
                policyGeneration: $this->policyGeneration,
            ), $boundary);

            return (string) $this->reader->read(
                $capability,
                $boundary,
                $media,
                'source_uri',
            );
        } finally {
            $this->capabilities->revokeBoundary($boundary);
        }
    }
}
