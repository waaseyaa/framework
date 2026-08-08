<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Bootstrap;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\User\UserSelfProfileReaderInterface;
use Waaseyaa\Access\User\UserSelfProfileSnapshot;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Entity\EntityInterface;

/** Account-bound audited reader for the current user's own profile identity. @api */
final readonly class AuditedUserSelfProfileReader implements UserSelfProfileReaderInterface
{
    private const string ISSUER = 'user.self-profile';

    public function __construct(
        private AuditedFieldRead $reader,
        private CapabilityRegistryInterface $capabilities,
        private string $classificationGeneration = 'field-read-active',
        private string $policyGeneration = 'field-read-active',
    ) {
        $this->capabilities->register(new CapabilityDeclaration(
            issuer: self::ISSUER,
            reason: CapabilityReason::SelfProfile,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['name', 'mail'],
            actorSemantics: [CapabilityActorSemantics::Account],
            maxTtlSeconds: 30,
            justification: 'Read the authenticated account own profile identity for an interactive self-service surface.',
            bindTenantFromContext: true,
            bindCommunityFromContext: true,
        ));
    }

    public function read(EntityInterface $user, AuthorizationPrincipalInterface $actor): UserSelfProfileSnapshot
    {
        if (!$actor->isAuthenticated() || $user->id() === null || (string) $user->id() !== (string) $actor->id()) {
            throw new \LogicException('Self-profile identity may only be read by its authenticated account.');
        }

        $boundary = $this->capabilities->openBoundary('self-profile:' . bin2hex(random_bytes(12)));
        try {
            $capability = $this->capabilities->issueValueRead(self::ISSUER, new CapabilityIssueContext(
                executionBoundary: $boundary->correlationId,
                actorSemantics: CapabilityActorSemantics::Account,
                actorId: $actor->id(),
                tenantId: $actor->tenantId(),
                communityId: $actor->communityId(),
                expiresAt: new \DateTimeImmutable('+30 seconds'),
                classificationGeneration: $this->classificationGeneration,
                policyGeneration: $this->policyGeneration,
            ), $boundary);
            $values = $this->reader->readMany(
                $capability,
                $boundary,
                $user,
                ['name', 'mail'],
                CapabilityReason::SelfProfile,
            );

            return new UserSelfProfileSnapshot(
                (string) ($values['name'] ?? ''),
                (string) ($values['mail'] ?? ''),
            );
        } finally {
            $this->capabilities->revokeBoundary($boundary);
        }
    }
}
