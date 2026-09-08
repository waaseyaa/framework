<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Outcome of a strict read of `.waaseyaa/bimaaji-install.json`.
 *
 * Unlike {@see InstalledManifest::load()}, which fails soft so the installer
 * can prune nothing rather than guess, verification uses this enum to report
 * missing, unreadable, malformed, and unsupported-schema manifests distinctly.
 *
 * @api
 */
enum ManifestReadStatus: string
{
    case Ok = 'ok';
    case Missing = 'missing';
    case Unreadable = 'unreadable';
    case Malformed = 'malformed';
    case UnsupportedSchema = 'unsupported_schema';
}
