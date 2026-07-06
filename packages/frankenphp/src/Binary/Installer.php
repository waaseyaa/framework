<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Binary;

/**
 * Downloads + installs a {@see FrankenPhpAsset} to a managed path, idempotently.
 *
 * POSIX assets are a bare binary installed directly under the base dir
 * (`vendor/bin/frankenphp`). The Windows asset is a full PHP-for-Windows SDK zip
 * (frankenphp.exe + ~80 sibling DLLs); it is FULLY extracted into a managed dist
 * subdir (`vendor/bin/frankenphp-dist/`) so the exe finds its DLLs. The bundled
 * php.exe is never invoked and the dir is never put on PATH — `dev` execs
 * frankenphp.exe by full path.
 *
 * I/O (HTTP download, sha256, archive extraction, chmod) is injected as closures
 * so the orchestration — idempotency, checksum enforcement, archive vs bare
 * binary, POSIX executable bit — is unit-testable offline. The defaults are real
 * implementations on PHP streams (system PHP's OpenSSL, never FrankenPHP's
 * bundled php) + ext-zip or bsdtar for extraction.
 *
 * @api
 */
final class Installer
{
    /** @var \Closure(string $url, string $destFile): void */
    private \Closure $download;

    /** @var \Closure(string $apiUrl, string $assetName): ?string */
    private \Closure $fetchDigest;

    /** @var \Closure(string $file): string */
    private \Closure $sha256File;

    /** @var \Closure(string $zipFile, string $destDir): void */
    private \Closure $extractAll;

    /** @var \Closure(string $file): void */
    private \Closure $makeExecutable;

    /**
     * @param \Closure(string,string):void|null    $download        fn(url, destFile)
     * @param \Closure(string,string):?string|null $fetchDigest     fn(apiUrl, assetName) → sha256 hex or null
     * @param \Closure(string):string|null         $sha256File      fn(file) → sha256 hex
     * @param \Closure(string,string):void|null    $extractAll      fn(zipFile, destDir) — extract the whole archive
     * @param \Closure(string):void|null           $makeExecutable  fn(file)
     * @param bool                                 $allowUnverified explicit operator opt-out: proceed with an
     *                                                               unverified install when the release digest is
     *                                                               unavailable, instead of the fail-closed default
     */
    public function __construct(
        ?\Closure $download = null,
        ?\Closure $fetchDigest = null,
        ?\Closure $sha256File = null,
        ?\Closure $extractAll = null,
        ?\Closure $makeExecutable = null,
        private readonly bool $allowUnverified = false,
    ) {
        $this->download = $download ?? self::realDownload();
        $this->fetchDigest = $fetchDigest ?? self::realFetchDigest();
        $this->sha256File = $sha256File ?? static function (string $file): string {
            $hash = hash_file('sha256', $file);

            return $hash === false ? '' : $hash;
        };
        $this->extractAll = $extractAll ?? self::realExtractAll();
        $this->makeExecutable = $makeExecutable ?? static function (string $file): void {
            @chmod($file, 0o755);
        };
    }

    /**
     * The on-disk path of the installed binary for an asset under a base dir.
     * Windows binaries live inside the extracted SDK dir; POSIX binaries are
     * directly under the base dir. Pure.
     */
    public static function binaryPath(string $baseDir, FrankenPhpAsset $asset): string
    {
        $base = rtrim($baseDir, '\\/');

        return $asset->isArchive
            ? $base . '/' . BinaryResolver::WINDOWS_DIST_DIR . '/' . $asset->binaryName
            : $base . '/' . $asset->binaryName;
    }

    /**
     * Install the asset under `$baseDir` (a project's vendor/bin), returning the
     * outcome (the path is the runnable binary).
     *
     * Idempotent: an existing binary is kept unless `$force`. Checksum verification
     * is fail-closed by default: a mismatch always refuses to install, and when the
     * digest itself is unavailable (GitHub API unreachable/rate-limited, malformed
     * response, or the asset publishes no digest) the install now also refuses,
     * unless the operator has explicitly opted out via `$allowUnverified` in the
     * constructor — in which case the install proceeds and `InstallOutcome`
     * reports `checksumVerified: false` for the caller to warn on.
     *
     * @throws \RuntimeException on download failure, checksum mismatch, extraction
     *                           failure, or an unavailable checksum with no opt-out
     */
    public function install(FrankenPhpAsset $asset, string $baseDir, bool $force = false): InstallOutcome
    {
        $binary = self::binaryPath($baseDir, $asset);

        if (!$force && is_file($binary)) {
            return new InstallOutcome($binary, skipped: true, checksumVerified: false);
        }

        if (!is_dir($baseDir) && !@mkdir($baseDir, 0o755, true) && !is_dir($baseDir)) {
            throw new \RuntimeException(sprintf('Could not create install directory "%s".', $baseDir));
        }

        $tmp = rtrim($baseDir, '\\/') . '/.frankenphp-' . $asset->version . '.download';
        @unlink($tmp);

        ($this->download)($asset->url, $tmp);
        if (!is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Download produced no file from %s.', $asset->url));
        }

        $verified = false;
        $expected = ($this->fetchDigest)($asset->apiUrl, $asset->assetName);
        if ($expected !== null && $expected !== '') {
            $actual = ($this->sha256File)($tmp);
            if (!hash_equals(strtolower($expected), strtolower($actual))) {
                @unlink($tmp);
                throw new \RuntimeException(sprintf(
                    'Checksum mismatch for %s: expected %s, got %s. Refusing to install.',
                    $asset->assetName,
                    $expected,
                    $actual,
                ));
            }
            $verified = true;
        } elseif (!$this->allowUnverified) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf(
                'Could not verify the checksum for %s: the GitHub release digest was unavailable '
                . '(API unreachable/rate-limited, or the asset publishes no digest). Refusing to install an '
                . 'unverified binary. Pass --allow-unverified to accept this risk explicitly.',
                $asset->assetName,
            ));
        }
        // else: operator explicitly opted into an unverified install; $verified stays
        // false and InstallOutcome::checksumVerified reports it for the caller to warn on.

        if ($asset->isArchive) {
            $distDir = \dirname($binary);
            if (!is_dir($distDir) && !@mkdir($distDir, 0o755, true) && !is_dir($distDir)) {
                @unlink($tmp);
                throw new \RuntimeException(sprintf('Could not create extraction directory "%s".', $distDir));
            }
            ($this->extractAll)($tmp, $distDir);
            @unlink($tmp);
            // The Windows SDK ships php.ini-development/production templates but no
            // active php.ini, so the bundled PHP runs with opcache on (fatals
            // under ASLR) and NO extensions loaded (pdo_sqlite is a DLL, not
            // built-in as on the Linux static build). Write a minimal working
            // php.ini so `frankenphp.exe` (auto-loads php.ini from its own dir)
            // serves Waaseyaa out of the box.
            @file_put_contents($distDir . '/php.ini', self::windowsRuntimeIni($distDir . '/ext'));
        } else {
            @unlink($binary);
            if (!@rename($tmp, $binary)) {
                if (!@copy($tmp, $binary)) {
                    @unlink($tmp);
                    throw new \RuntimeException(sprintf('Could not move the downloaded binary into place at %s.', $binary));
                }
                @unlink($tmp);
            }
            // Bare POSIX binaries need the executable bit; the Windows .exe does not.
            ($this->makeExecutable)($binary);
        }

        if (!is_file($binary)) {
            throw new \RuntimeException(sprintf(
                'Install completed but no binary is present at %s (the archive may not contain %s at its root).',
                $binary,
                $asset->binaryName,
            ));
        }

        return new InstallOutcome($binary, skipped: false, checksumVerified: $verified);
    }

    /**
     * Minimal working php.ini for the FrankenPHP Windows SDK. Pure.
     *
     * Enables the SQLite drivers from the SDK's `ext/` dir (pdo_sqlite is a DLL
     * on Windows, not built in), disables opcache (its opcode handlers fatal
     * under Windows ASLR), and sets SSE-friendly buffering for the live admin
     * `/api/broadcast` stream. frankenphp.exe auto-loads `php.ini` from its own
     * directory, so no PATH / env wiring is needed.
     */
    public static function windowsRuntimeIni(string $extDir): string
    {
        return <<<INI
            ; Waaseyaa FrankenPHP dev runtime — generated by `waaseyaa frankenphp:install`.
            ; Regenerated on re-install; edit at your own risk.
            extension_dir = "{$extDir}"
            extension = pdo_sqlite
            extension = sqlite3
            ; The Windows SDK's opcache opcode handlers fatal under ASLR — keep it off for dev.
            opcache.enable = 0
            opcache.enable_cli = 0
            ; SSE-friendly (live admin /api/broadcast):
            output_buffering = Off
            implicit_flush = On

            INI;
    }

    /**
     * @return \Closure(string,string):void
     */
    private static function realDownload(): \Closure
    {
        return static function (string $url, string $destFile): void {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'follow_location' => 1,
                    'max_redirects' => 5,
                    'timeout' => 300,
                    'user_agent' => 'waaseyaa-frankenphp-installer',
                    'header' => ['Accept: application/octet-stream'],
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $in = @fopen($url, 'rb', false, $context);
            if ($in === false) {
                throw new \RuntimeException(sprintf('Could not open download stream for %s.', $url));
            }
            $out = @fopen($destFile, 'wb');
            if ($out === false) {
                fclose($in);
                throw new \RuntimeException(sprintf('Could not open %s for writing.', $destFile));
            }
            $bytes = stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            if ($bytes === false || $bytes === 0) {
                @unlink($destFile);
                throw new \RuntimeException(sprintf('Empty download from %s.', $url));
            }
        };
    }

    /**
     * @return \Closure(string,string):?string
     */
    private static function realFetchDigest(): \Closure
    {
        return static function (string $apiUrl, string $assetName): ?string {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 30,
                    'user_agent' => 'waaseyaa-frankenphp-installer',
                    'header' => ['Accept: application/vnd.github+json'],
                    'ignore_errors' => true,
                ],
            ]);
            $json = @file_get_contents($apiUrl, false, $context);
            if (!is_string($json) || $json === '') {
                return null; // API unreachable / rate-limited → caller decides fail-closed vs opt-out.
            }
            try {
                $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            if (!is_array($data) || !isset($data['assets']) || !is_array($data['assets'])) {
                return null;
            }
            foreach ($data['assets'] as $asset) {
                if (is_array($asset) && ($asset['name'] ?? null) === $assetName) {
                    $digest = $asset['digest'] ?? null;
                    if (is_string($digest) && str_starts_with($digest, 'sha256:')) {
                        return substr($digest, 7);
                    }
                }
            }

            return null;
        };
    }

    /**
     * Extract an entire zip into a directory. Prefers ext-zip; falls back to
     * bsdtar (Windows 10+ `tar.exe`, macOS, most Linux) — both shell-free.
     *
     * @return \Closure(string,string):void
     */
    private static function realExtractAll(): \Closure
    {
        return static function (string $zipFile, string $destDir): void {
            if (class_exists(\ZipArchive::class)) {
                $zip = new \ZipArchive();
                if ($zip->open($zipFile) !== true) {
                    throw new \RuntimeException(sprintf('Could not open the downloaded archive %s.', $zipFile));
                }
                $ok = $zip->extractTo($destDir);
                $zip->close();
                if (!$ok) {
                    throw new \RuntimeException(sprintf('Could not extract the archive into %s.', $destDir));
                }

                return;
            }

            // Fallback: bsdtar reads zip and ships with Windows 10+ (tar.exe),
            // macOS, and most Linux. Shell-free (array form).
            $process = @proc_open(
                ['tar', '-xf', $zipFile, '-C', $destDir],
                [\STDIN, \STDOUT, \STDERR],
                $pipes,
            );
            if (\is_resource($process) && proc_close($process) === 0) {
                return;
            }

            throw new \RuntimeException(
                'Could not extract the FrankenPHP Windows archive: PHP ext-zip is not enabled and no working `tar` '
                . 'was found. Enable ext-zip, or set FRANKENPHP_BIN to an already-extracted frankenphp.exe.',
            );
        };
    }
}
