<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Contract;

use Waaseyaa\Audit\Entity\AuditEvent;

/**
 * Read-side contract for querying the OCAP audit log.
 *
 * @api
 */
interface AuditQueryInterface
{
    /**
     * @return iterable<AuditEvent>
     */
    public function findBy(AuditQuery $query): iterable;

    public function count(AuditQuery $query): int;
}
