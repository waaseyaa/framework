<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

/** Explicit isolated validation never establishes actual runtime qualification. @api */
enum ManifestCompilationContext: string
{
    case ArtifactBound = 'artifact-bound';
    case IsolatedValidation = 'isolated-validation';

    /** The selected layout requires POSIX identity, link and mode custody. @api */
    public static function artifactBindingSupported(): bool
    {
        return PHP_OS_FAMILY !== 'Windows';
    }
}
