<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\StaffDirectory;

use Waaseyaa\Entity\EntityInterface;

/** Authorized roster entities plus the full survivor count for pagination. @api */
final readonly class StaffDirectoryPage
{
    /** @param list<EntityInterface> $members */
    public function __construct(public array $members, public int $total) {}
}
