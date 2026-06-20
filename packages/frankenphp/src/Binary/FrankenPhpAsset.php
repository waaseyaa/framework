<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Binary;

/**
 * A resolved FrankenPHP release asset for one OS/arch: the exact filename to
 * download, its download URL, whether it ships as an archive (Windows only), and
 * the on-disk binary name to install.
 *
 * Pure value object — produced by {@see AssetSelector}, consumed by the installer.
 *
 * @api
 */
final readonly class FrankenPhpAsset
{
    public function __construct(
        /** Pinned release tag, e.g. "v1.12.4". */
        public string $version,
        /** Exact GitHub release asset filename, e.g. "frankenphp-linux-x86_64" or "frankenphp-windows-x86_64.zip". */
        public string $assetName,
        /** Full download URL for the asset. */
        public string $url,
        /** GitHub releases API URL for the tag (carries per-asset sha256 in the `digest` field). */
        public string $apiUrl,
        /**
         * True when the asset is a zip that must be FULLY extracted (Windows).
         * The Windows release is a complete PHP-for-Windows SDK: frankenphp.exe
         * plus its sibling DLLs. Extracting only the exe yields a DLL-not-found
         * failure, so the whole archive is extracted into a managed dist dir.
         */
        public bool $isArchive,
        /** On-disk binary filename, e.g. "frankenphp" or "frankenphp.exe". */
        public string $binaryName,
    ) {}
}
