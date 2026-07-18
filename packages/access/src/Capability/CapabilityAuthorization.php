<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Capability;

/** Registry-owned authorization record for one opaque handle. @api */
final readonly class CapabilityAuthorization
{
    public function __construct(
        public CapabilityDeclaration $declaration,
        public CapabilityIssueContext $context,
    ) {}
}
