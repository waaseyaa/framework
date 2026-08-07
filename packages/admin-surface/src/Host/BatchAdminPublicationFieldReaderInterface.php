<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

/** Transactional batch projection for an already authorized admin-list scope. @api */
interface BatchAdminPublicationFieldReaderInterface extends AdminPublicationFieldReaderInterface
{
    /**
     * @param list<EntityInterface> $entities
     * @return list<array{workflow_state?: mixed, status?: mixed}>
     */
    public function readMany(array $entities, AccountInterface $account): array;
}
