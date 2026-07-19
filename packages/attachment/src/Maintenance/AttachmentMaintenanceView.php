<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Maintenance;

/** Exact persistence-maintenance projection for attachment invariants. @internal */
final readonly class AttachmentMaintenanceView
{
    public function __construct(
        public string $parentEntityType,
        public string $parentEntityId,
        public mixed $active,
    ) {}
}
