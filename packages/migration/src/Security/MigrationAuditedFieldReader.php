<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Security;

use Waaseyaa\Access\Capability\CapabilityExecutionBoundary;
use Waaseyaa\Access\Capability\PrivilegedFieldReadCapability;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Entity\EntityInterface;

/** Explicit, greppable privileged-read call site supplied to migration code. @api */
final readonly class MigrationAuditedFieldReader
{
    public function __construct(
        private AuditedFieldRead $auditedFieldRead,
        private PrivilegedFieldReadCapability $capability,
        private CapabilityExecutionBoundary $boundary,
    ) {}

    public function read(EntityInterface $entity, string $field): mixed
    {
        return $this->auditedFieldRead->read($this->capability, $this->boundary, $entity, $field);
    }
}
