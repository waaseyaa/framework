<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Version;

/** @api */
enum ManifestVersionDisposition: string
{
    case Current = 'current';
    case MigrationRequired = 'migration_required';
    case UnsupportedFuture = 'unsupported_future';
}
