<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

final class AdminBuildPolicyException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('Admin build refused by policy (' . $errorCode . ').');
    }
}
