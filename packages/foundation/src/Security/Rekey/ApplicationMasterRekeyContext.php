<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;

/** Exact request and guarded keyring passed to an adapter operation. @api */
final readonly class ApplicationMasterRekeyContext
{
    public function __construct(
        public ApplicationMasterRekeyRecord $request,
        public ApplicationMasterKeyring $keyring,
        public DatabaseInterface $database,
    ) {}
}
