<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Binary;

/**
 * Maps a runtime OS/arch to the exact official FrankenPHP release asset.
 *
 * Pure + total: same inputs → same {@see FrankenPhpAsset}; unsupported
 * OS/arch throw a clear error rather than constructing a non-existent URL.
 *
 * Asset naming is verified against the official php/frankenphp releases:
 *   - Linux:   `frankenphp-linux-x86_64`   / `frankenphp-linux-aarch64`  (bare ELF)
 *   - macOS:   `frankenphp-mac-x86_64`     / `frankenphp-mac-arm64`      (bare Mach-O)
 *   - Windows: `frankenphp-windows-x86_64.zip`  (a ZIP — extract `frankenphp.exe`; there is
 *              NO bare `.exe` asset and NO windows-arm64 build)
 *
 * Arch tokens differ by OS for the SAME CPU (Linux says `aarch64`, macOS says
 * `arm64`), and `php_uname('m')` reports OS-specific machine strings (Windows
 * `AMD64`, Linux `x86_64`/`aarch64`, macOS `x86_64`/`arm64`), so the raw machine
 * value is normalized first and the OS-appropriate token re-emitted.
 *
 * @api
 */
final class AssetSelector
{
    /**
     * Known-good pinned default. Override per-call (CLI flag / env) when needed.
     */
    public const string DEFAULT_VERSION = 'v1.12.4';

    public const string REPO = 'php/frankenphp';

    /**
     * @param string      $osFamily PHP_OS_FAMILY ("Windows" | "Darwin" | "Linux" | …)
     * @param string      $machine  php_uname('m') (e.g. "AMD64", "x86_64", "arm64", "aarch64")
     * @param string|null $version  release tag; defaults to {@see DEFAULT_VERSION}. "1.12.4" and "v1.12.4" both accepted.
     *
     * @throws \RuntimeException for an unsupported OS family or CPU architecture
     */
    public function select(string $osFamily, string $machine, ?string $version = null): FrankenPhpAsset
    {
        $version = $this->normalizeVersion(($version === null || $version === '') ? self::DEFAULT_VERSION : $version);
        $arch = $this->normalizeArch($machine); // 'x86_64' | 'arm64'

        if ($osFamily === 'Windows') {
            if ($arch !== 'x86_64') {
                throw new \RuntimeException(sprintf(
                    'FrankenPHP publishes no Windows build for CPU architecture "%s" — only Windows x86_64 is available.',
                    $machine,
                ));
            }
            $assetName = 'frankenphp-windows-x86_64.zip';

            return new FrankenPhpAsset(
                version: $version,
                assetName: $assetName,
                url: $this->downloadUrl($version, $assetName),
                apiUrl: $this->apiUrl($version),
                isArchive: true,
                binaryName: 'frankenphp.exe',
            );
        }

        $osToken = match ($osFamily) {
            'Darwin' => 'mac',
            'Linux' => 'linux',
            default => throw new \RuntimeException(sprintf(
                'Unsupported OS family "%s" — FrankenPHP binaries are published for Linux, macOS, and Windows only.',
                $osFamily,
            )),
        };

        // Linux uses the `aarch64` token; macOS uses `arm64`. x86_64 is the same on both.
        $archToken = match (true) {
            $arch === 'x86_64' => 'x86_64',
            $osToken === 'linux' => 'aarch64',
            default => 'arm64',
        };
        $assetName = sprintf('frankenphp-%s-%s', $osToken, $archToken);

        return new FrankenPhpAsset(
            version: $version,
            assetName: $assetName,
            url: $this->downloadUrl($version, $assetName),
            apiUrl: $this->apiUrl($version),
            isArchive: false,
            binaryName: 'frankenphp',
        );
    }

    private function normalizeArch(string $machine): string
    {
        $m = strtolower(trim($machine));

        return match (true) {
            in_array($m, ['x86_64', 'amd64', 'x86-64', 'x64'], true) => 'x86_64',
            in_array($m, ['arm64', 'aarch64'], true) => 'arm64',
            default => throw new \RuntimeException(sprintf(
                'Unsupported CPU architecture "%s" — FrankenPHP ships x86_64 and arm64 builds only.',
                $machine,
            )),
        };
    }

    private function normalizeVersion(string $version): string
    {
        return str_starts_with($version, 'v') ? $version : 'v' . $version;
    }

    private function downloadUrl(string $version, string $assetName): string
    {
        return sprintf('https://github.com/%s/releases/download/%s/%s', self::REPO, $version, $assetName);
    }

    private function apiUrl(string $version): string
    {
        return sprintf('https://api.github.com/repos/%s/releases/tags/%s', self::REPO, $version);
    }
}
