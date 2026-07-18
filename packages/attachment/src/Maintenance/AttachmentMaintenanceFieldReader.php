<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Maintenance;

use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Entity\EntityBase;

/** Closed, fixed-shape persistence reader; callers cannot select fields. @internal */
final readonly class AttachmentMaintenanceFieldReader
{
    /** @var \Closure(Attachment): AttachmentMaintenanceView */
    private \Closure $obtain;

    public function __construct()
    {
        $this->obtain = \Closure::bind(
            static function (Attachment $attachment): AttachmentMaintenanceView {
                $values = $attachment->valueContainer->rawValues();

                return new AttachmentMaintenanceView(
                    parentEntityType: (string) ($values['parent_entity_type'] ?? ''),
                    parentEntityId: (string) ($values['parent_entity_id'] ?? ''),
                    active: $values['is_active'] ?? null,
                );
            },
            null,
            EntityBase::class,
        );
    }

    public function read(Attachment $attachment): AttachmentMaintenanceView
    {
        return ($this->obtain)($attachment);
    }
}
