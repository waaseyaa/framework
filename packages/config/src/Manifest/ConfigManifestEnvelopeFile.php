<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

/**
 * The signed envelope's on-disk location beside an authored sync directory.
 *
 * The envelope is a **sibling** of the sync directory — `config/sync` is
 * authorized by `config/sync.envelope.json` — for a structural reason rather
 * than a stylistic one: {@see \Waaseyaa\Config\Sync\ConfigSyncBundleValidator}
 * is strict and complete, so every file inside the sync directory must be a
 * valid versioned config sync file. An envelope placed inside would fail the
 * very bundle it authorizes.
 *
 * Deriving the sidecar from the sync path also means it inherits the governed
 * sync selector and its provenance rules, instead of introducing a second
 * separately configured location that could drift from the bundle it signs.
 *
 * This class moves bytes only. It never verifies a signature, and reading an
 * envelope grants nothing on its own — verification belongs to
 * {@see ConfigManifestEnvelopeVerifier}.
 *
 * @api
 */
final class ConfigManifestEnvelopeFile
{
    public const SUFFIX = '.envelope.json';

    public static function pathFor(string $syncPath): string
    {
        return rtrim(str_replace('\\', '/', $syncPath), '/') . self::SUFFIX;
    }

    /**
     * Read the envelope authorizing `$syncPath`, or null when none is authored.
     *
     * Absence is not an error: a site may legitimately hold no envelope yet,
     * and the import preflight turns that into a refusal with a reason an
     * operator can act on. Malformed or symlinked bytes ARE an error — they mean
     * something is present and untrustworthy, which must never be confused with
     * nothing being present.
     */
    public static function read(string $syncPath): ?SignedConfigManifestEnvelope
    {
        $path = self::pathFor($syncPath);
        if (is_link($path)) {
            throw new \RuntimeException(sprintf(
                'Configuration manifest envelope %s is a symbolic link; the verified path reads only exact reviewed bytes.',
                $path,
            ));
        }
        if (!file_exists($path)) {
            return null;
        }
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Configuration manifest envelope %s is not a regular file.', $path));
        }

        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException(sprintf('Configuration manifest envelope %s is unreadable or empty.', $path));
        }

        try {
            $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                sprintf('Configuration manifest envelope %s is not valid JSON.', $path),
                previous: $exception,
            );
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new \RuntimeException(sprintf('Configuration manifest envelope %s must be a JSON object.', $path));
        }

        try {
            return SignedConfigManifestEnvelope::fromArray($document);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                sprintf('Configuration manifest envelope %s is malformed: %s', $path, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    /**
     * Write the envelope atomically and return its path.
     *
     * Write-to-temp-then-rename, because a partially written sidecar is worse
     * than an absent one: verification would fail in a way indistinguishable
     * from tampering. The temporary file is created in the destination
     * directory so the rename stays on one filesystem, and is removed on any
     * failure so an interrupted authoring run leaves nothing behind.
     */
    public static function write(string $syncPath, SignedConfigManifestEnvelope $envelope): string
    {
        $path = self::pathFor($syncPath);
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            throw new \RuntimeException(sprintf('Configuration manifest envelope directory %s does not exist.', $directory));
        }
        if (is_link($path)) {
            throw new \RuntimeException(sprintf('Refusing to write configuration manifest envelope through the symbolic link %s.', $path));
        }

        $bytes = json_encode($envelope->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $temporary = tempnam($directory, '.envelope-');
        if (!is_string($temporary)) {
            throw new \RuntimeException(sprintf('Could not create a temporary file beside %s.', $path));
        }

        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException(sprintf('Could not write the configuration manifest envelope beside %s.', $path));
            }
            chmod($temporary, 0o644);
            if (!rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('Could not move the configuration manifest envelope into place at %s.', $path));
            }
        } catch (\Throwable $exception) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw $exception;
        }

        return $path;
    }
}
