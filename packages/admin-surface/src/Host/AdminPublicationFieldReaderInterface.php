<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Closed projection for publication metadata on an authorized admin-list row.
 *
 * @api
 */
interface AdminPublicationFieldReaderInterface
{
    public function projects(EntityInterface $entity, string $field): bool;

    /**
     * @return array{workflow_state?: mixed, status?: mixed}
     */
    public function read(EntityInterface $entity, AccountInterface $account): array;
}
