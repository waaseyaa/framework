<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Bootstrap;

use Waaseyaa\Access\Capability\CapabilityExecutionBoundary;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\PrivilegedFieldReadCapability;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Entity\EntityInterface;

/** Closed credential verification primitive. @api */
final readonly class CredentialBootstrapReader
{
    public function __construct(private AuditedFieldRead $reader) {}

    /** @param non-empty-list<string> $fields @return array<string, mixed> */
    public function read(PrivilegedFieldReadCapability $capability, CapabilityExecutionBoundary $boundary, EntityInterface $account, array $fields): array
    {
        return $this->reader->readMany($capability, $boundary, $account, $fields, CapabilityReason::CredentialVerification);
    }
}
