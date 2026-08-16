<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** Closed transition strategies for application-master-derived purposes. @api */
enum ApplicationMasterPurposeStrategy: string
{
    case ReencryptCiphertext = 'reencrypt-ciphertext';
    case RecomputeLookupIndex = 'recompute-lookup-index';
    case RetainHistoricVerifier = 'retain-historic-verifier';
    case InvalidateRebuildable = 'invalidate-rebuildable';
    case DrainOrExpire = 'drain-or-expire';
    case EphemeralNoPersistence = 'ephemeral-no-persistence';
}
