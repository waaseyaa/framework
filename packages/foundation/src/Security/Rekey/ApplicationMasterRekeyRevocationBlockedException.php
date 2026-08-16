<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

/** Missing purpose, fleet, rollback, cache, or backup proof. @api */
final class ApplicationMasterRekeyRevocationBlockedException extends \RuntimeException {}
