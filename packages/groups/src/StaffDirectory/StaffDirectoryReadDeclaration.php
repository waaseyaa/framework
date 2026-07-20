<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\StaffDirectory;

/** Host declaration binding a staff capability to one exact roster bundle. @api */
final readonly class StaffDirectoryReadDeclaration
{
    public function __construct(
        public string $capability,
        public string $rosterGroupBundle,
    ) {
        if ($capability === '') {
            throw new \InvalidArgumentException('A staff directory declaration requires a capability.');
        }
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $rosterGroupBundle) !== 1) {
            throw new \InvalidArgumentException('A staff directory roster bundle must be a machine name.');
        }
    }
}
