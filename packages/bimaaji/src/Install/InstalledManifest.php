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
