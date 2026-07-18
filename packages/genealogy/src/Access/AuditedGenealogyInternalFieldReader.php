<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Access;

use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Entity\EntityInterface;

/** Strictly audited typed reader for the Internal genealogy deletion tombstone. @api */
final readonly class AuditedGenealogyInternalFieldReader implements GenealogyInternalFieldReaderInterface
{
    public function __construct(
        private AuditedFieldRead $reader,
        private CapabilityRegistryInterface $capabilities,
    ) {}

    public function isTombstoned(EntityInterface $entity): bool
    {
        if (!in_array($entity->getEntityTypeId(), ['genealogy_person', 'genealogy_family', 'genealogy_event'], true)) {
            return false;
        }

        $boundary = $this->capabilities->openBoundary(bin2hex(random_bytes(16)));
        $capability = $this->capabilities->issueValueRead('genealogy.tombstone', new CapabilityIssueContext(
            executionBoundary: $boundary->correlationId,
            actorSemantics: CapabilityActorSemantics::NoActingContext,
            actorId: null,
            tenantId: null,
            communityId: null,
            expiresAt: new \DateTimeImmutable('+30 seconds'),
            classificationGeneration: 'runtime',
            policyGeneration: 'runtime',
        ), $boundary);
        try {
            $value = $this->reader->read(
                $capability,
                $boundary,
                $entity,
                'deleted_at',
            );

            return is_string($value) && trim($value) !== '';
        } finally {
            $this->capabilities->revokeBoundary($boundary);
        }
    }
}
