<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

/** Explicit isolated validation never establishes actual runtime qualification. @api */
enum ManifestCompilationContext: string
{
    case ArtifactBound = 'artifact-bound';
    case IsolatedValidation = 'isolated-validation';
}
