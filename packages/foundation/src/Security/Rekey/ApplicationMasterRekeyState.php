<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

/** Closed persisted CFG-04 application-master rekey states. @api */
enum ApplicationMasterRekeyState: string
{
    case PrepareAndAuthorize = 'prepare-and-authorize';
    case InstallNewActiveWithOldLegacyReadVerify = 'install-new-active-with-old-legacy-read-verify';
    case EnumerateSnapshot = 'enumerate-snapshot';
    case TransitionBoundedBatches = 'transition-bounded-batches';
    case VerifyEveryPurpose = 'verify-every-purpose';
    case ReconcileWritersAndWorkers = 'reconcile-writers-and-workers';
    case HoldAndOptionallyExecuteRollbackWindow = 'hold-and-optionally-execute-rollback-window';
    case ProveBackupRetentionOrRestoreCompatibility = 'prove-backup-retention-or-restore-compatibility';
    case RevokeOldInLedger = 'revoke-old-in-ledger';
    case RollingBack = 'rolling-back';
    case RolledBack = 'rolled-back';
}
