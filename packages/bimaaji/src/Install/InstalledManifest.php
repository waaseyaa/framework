<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Record of what `bimaaji:install` has generated in a consumer project.
 *
 * Installation used to visit only the *current* target set, so a skill
 * removed or renamed upstream left its previously generated `SKILL.md` on
 * disk forever. Claude Code kept discovering retired guidance, and a
 * consumer upgrading across releases accumulated the union of every skill
 * set they had ever installed.
 *
 * Pruning that safely needs **recorded** ownership, not inferred ownership.
 * A `waaseyaa-*` filename is a guess, and the marker-bounded splice means a
 * generated file can legitimately carry hand-authored content, so a filename
 * match is not a licence to delete. This manifest is the record: for each
 * client it stores the exact relative path of every file the command wrote,
 * plus the sha1 of the bytes it left on disk.
 *
 * The file lives at `.waaseyaa/bimaaji-install.json`, alongside the other
 * consumer-root `.waaseyaa/` artifacts, and is meant to be committed — it is
 * the provenance for the generated files committed beside it.
 *
 * Ownership is only ever *narrowed* by the pruner, never widened: a path
 * absent from the manifest is never touched, whatever it is called.
 *
 * @api
 */
final class InstalledManifest
{
    /** Relative path, from the project root, of the manifest itself. */
    public const string RELATIVE_PATH = '.waaseyaa/bimaaji-install.json';

    public const int SCHEMA_VERSION = 1;

    /**
     * @param array<string, array<string, string>> $clients clientId => (relative target path => sha1 of the bytes written)
     */
    private function __construct(
        private readonly array $clients,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Read the manifest from a project root.
     *
     * A missing, unreadable, malformed, or future-schema manifest yields an
     * empty record rather than an error: the worst outcome is that nothing is
     * pruned this run, which is strictly safer than guessing at ownership from
     * a file we could not parse.
     */
    public static function load(string $projectRoot): self
    {
        $path = $projectRoot . DIRECTORY_SEPARATOR . self::RELATIVE_PATH;
        if (!is_file($path) || !is_readable($path)) {
            return self::empty();
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return self::empty();
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::empty();
        }

        if (!is_array($decoded) || ($decoded['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return self::empty();
        }

        $clients = [];
        $rawClients = $decoded['clients'] ?? null;
        if (is_array($rawClients)) {
            foreach ($rawClients as $clientId => $entry) {
                if (!is_string($clientId) || !is_array($entry)) {
                    continue;
                }
                $targets = $entry['targets'] ?? null;
                if (!is_array($targets)) {
                    continue;
                }
                $recorded = [];
                foreach ($targets as $target) {
                    if (!is_array($target)) {
                        continue;
                    }
                    $targetPath = $target['path'] ?? null;
                    $sha1 = $target['sha1'] ?? null;
                    if (is_string($targetPath) && $targetPath !== '' && is_string($sha1) && $sha1 !== '') {
                        $recorded[$targetPath] = $sha1;
                    }
                }
                if ($recorded !== []) {
                    ksort($recorded);
                    $clients[$clientId] = $recorded;
                }
            }
        }
        ksort($clients);

        return new self($clients);
    }

    /**
     * Strict read for verification surfaces.
     *
     * Reports missing, unreadable, malformed, and future-schema manifests
     * distinctly. Every client row and target row must be well-formed; no
     * row is dropped. {@see load()} remains fail-soft for the installer.
     */
    public static function readStrict(string $projectRoot, ?InstallPathSandbox $sandbox = null): InstalledManifestReadResult
    {
        $resolvedRoot = realpath($projectRoot);
        if ($resolvedRoot === false) {
            return new InstalledManifestReadResult(
                status: ManifestReadStatus::Unreadable,
                detail: 'cannot resolve project root',
            );
        }

        $sandbox ??= new InstallPathSandbox();
        $manifestPath = $sandbox->resolveContainedPath(self::RELATIVE_PATH, $resolvedRoot);
        if ($manifestPath === null) {
            return new InstalledManifestReadResult(
                status: ManifestReadStatus::Unreadable,
                detail: 'manifest path is not contained in the project root',
            );
        }

        if (!is_file($manifestPath)) {
            return new InstalledManifestReadResult(
                status: ManifestReadStatus::Missing,
                detail: self::RELATIVE_PATH,
            );
        }

        if (!is_readable($manifestPath)) {
            return new InstalledManifestReadResult(
                status: ManifestReadStatus::Unreadable,
                detail: self::RELATIVE_PATH,
            );
        }

        $raw = $sandbox->readBoundedFile($manifestPath);
        if ($raw === null) {
            return new InstalledManifestReadResult(
                status: ManifestReadStatus::Unreadable,
                detail: 'manifest is unreadable or exceeds the verification size bound',
            );
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new InstalledManifestReadResult(
                status: ManifestReadStatus::Malformed,
                detail: 'invalid JSON',
            );
        }

        if (!is_array($decoded)) {
            return new InstalledManifestReadResult(
                status: ManifestReadStatus::Malformed,
                detail: 'root must be a JSON object',
            );
        }

        $schemaVersion = $decoded['schema_version'] ?? null;
        if (!is_int($schemaVersion)) {
            return new InstalledManifestReadResult(
                status: ManifestReadStatus::Malformed,
                detail: 'schema_version must be an integer',
            );
        }

        if ($schemaVersion > self::SCHEMA_VERSION) {
            return new InstalledManifestReadResult(
                status: ManifestReadStatus::UnsupportedSchema,
                detail: sprintf('schema_version %d is not supported', $schemaVersion),
            );
        }

        if ($schemaVersion !== self::SCHEMA_VERSION) {
            return new InstalledManifestReadResult(
                status: ManifestReadStatus::Malformed,
                detail: sprintf('schema_version %d is not recognised', $schemaVersion),
            );
        }

        $rawClients = $decoded['clients'] ?? null;
        if (!is_array($rawClients)) {
            return new InstalledManifestReadResult(
                status: ManifestReadStatus::Malformed,
                detail: 'clients must be an object',
            );
        }

        $clients = [];
        $seenPaths = [];
        foreach ($rawClients as $clientId => $entry) {
            if (!is_string($clientId) || !$sandbox->isSafeClientId($clientId)) {
                return new InstalledManifestReadResult(
                    status: ManifestReadStatus::Malformed,
                    detail: 'client id must be a non-empty string without ASCII control characters',
                );
            }

            if (!is_array($entry)) {
                return new InstalledManifestReadResult(
                    status: ManifestReadStatus::Malformed,
                    detail: sprintf('client "%s" must be an object', $clientId),
                );
            }

            $targets = $entry['targets'] ?? null;
            if (!is_array($targets) || !array_is_list($targets)) {
                return new InstalledManifestReadResult(
                    status: ManifestReadStatus::Malformed,
                    detail: sprintf('client "%s" targets must be a JSON array', $clientId),
                );
            }

            $recorded = [];
            foreach ($targets as $index => $target) {
                if (!is_array($target)) {
                    return new InstalledManifestReadResult(
                        status: ManifestReadStatus::Malformed,
                        detail: sprintf('client "%s" target row %d must be an object', $clientId, $index),
                    );
                }

                $targetPath = $target['path'] ?? null;
                $sha1 = $target['sha1'] ?? null;
                if (!is_string($targetPath) || !$sandbox->isSafeRelativePath($targetPath)) {
                    return new InstalledManifestReadResult(
                        status: ManifestReadStatus::Malformed,
                        detail: sprintf('client "%s" target row %d path must be a safe non-empty relative path', $clientId, $index),
                    );
                }

                if (!is_string($sha1) || !$sandbox->isValidSha1Digest($sha1)) {
                    return new InstalledManifestReadResult(
                        status: ManifestReadStatus::Malformed,
                        detail: sprintf('client "%s" target row %d sha1 must be a 40-character lowercase hex digest', $clientId, $index),
                    );
                }

                if (isset($recorded[$targetPath])) {
                    return new InstalledManifestReadResult(
                        status: ManifestReadStatus::Malformed,
                        detail: sprintf('client "%s" records duplicate path %s', $clientId, $targetPath),
                    );
                }

                if (isset($seenPaths[$targetPath])) {
                    return new InstalledManifestReadResult(
                        status: ManifestReadStatus::Malformed,
                        detail: sprintf('path %s is owned by both "%s" and "%s"', $targetPath, $seenPaths[$targetPath], $clientId),
                    );
                }

                $recorded[$targetPath] = $sha1;
                $seenPaths[$targetPath] = $clientId;
            }

            ksort($recorded);
            $clients[$clientId] = $recorded;
        }
        ksort($clients);

        return new InstalledManifestReadResult(
            status: ManifestReadStatus::Ok,
            manifest: new self($clients),
        );
    }

    /**
     * Paths this command previously wrote for one client, mapped to the sha1
     * of the bytes it left behind.
     *
     * @return array<string, string>
     */
    public function targetsFor(string $clientId): array
    {
        return $this->clients[$clientId] ?? [];
    }

    /**
     * @return list<string>
     */
    public function clientIds(): array
    {
        return array_keys($this->clients);
    }

    /**
     * Replace one client's record. Other clients are carried through
     * untouched, so installing for `cursor` never forgets what was written
     * for `claude`.
     *
     * @param array<string, string> $targets relative path => sha1
     */
    public function withClient(string $clientId, array $targets): self
    {
        $clients = $this->clients;
        if ($targets === []) {
            unset($clients[$clientId]);
        } else {
            ksort($targets);
            $clients[$clientId] = $targets;
        }
        ksort($clients);

        return new self($clients);
    }

    /**
     * Deterministic JSON, so re-running the install produces no diff when
     * nothing changed and the file is reviewable in a pull request.
     */
    public function toJson(): string
    {
        $clients = [];
        foreach ($this->clients as $clientId => $targets) {
            $rows = [];
            foreach ($targets as $targetPath => $sha1) {
                $rows[] = ['path' => $targetPath, 'sha1' => $sha1];
            }
            $clients[$clientId] = ['targets' => $rows];
        }

        return json_encode(
            [
                'schema_version' => self::SCHEMA_VERSION,
                'generated_by' => 'waaseyaa/bimaaji bimaaji:install',
                'clients' => $clients,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
