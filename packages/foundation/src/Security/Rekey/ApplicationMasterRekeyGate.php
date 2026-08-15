<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

/** Closed non-secret evidence gates required before predecessor revocation. @api */
enum ApplicationMasterRekeyGate: string
{
    case WritersAndWorkersReconciled = 'writers-and-workers-reconciled';
    case CachesReconciled = 'caches-reconciled';
    case RollbackWindowClosed = 'rollback-window-closed';
    case RetainedBackupsCompatibleOrExpired = 'retained-backups-compatible-or-expired';
}
