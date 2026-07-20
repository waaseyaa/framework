<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\StaffDirectory;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Entity\EntityInterface;

/** Capability-scoped staff roster list/detail read boundary. @api */
interface StaffDirectoryReaderInterface
{
    public function list(AuthorizationPrincipalInterface $principal, int $offset = 0, int $limit = 50): StaffDirectoryPage;

    public function detail(AuthorizationPrincipalInterface $principal, int|string $userId): ?EntityInterface;
}
