<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

final class AdminBuildArtifactScanException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('Admin build artifact scan refused (' . $errorCode . ').');
    }
}
