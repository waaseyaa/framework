<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Contract;

/** @api */
final readonly class PrivilegedReadReceipt
{
    public function __construct(public string $id)
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Privileged read receipt id cannot be empty.');
        }
    }
}
