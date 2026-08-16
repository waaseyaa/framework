<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

/** Stale request, cursor, or immutable identity conflict. @api */
final class ApplicationMasterRekeyConflictException extends \RuntimeException {}
