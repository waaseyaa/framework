<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling;

use RuntimeException;

/**
 * Package-local public-surface declaration plane
 * (docs/specs/public-surface-declarations.md §2-§4, FW-DELIVERY-SURFACE-01).
 *
 * Reads every `packages/<pkg>/public-surface.php`, validates the shape fail
 * closed, and composes the result into the single `FQCN => disposition` map
 * that `docs/public-surface-map.php` used to hand-carry.
 */
final class SurfaceDeclarations
{
    public const ALLOWED_DISPOSITIONS = ['public', 'internal', 'extract', 'remove'];

    private const DECLARATION_FILENAME = 'public-surface.php';

    /**
     * @param array<string, array{
     *   dir: string,
     *   file: string,
     *   prefixes: list<string>,
     *   entries: list<array{fqcn: mixed, disposition: mixed, purpose: mixed, ref: mixed}>,
     *   notes: list<mixed>,
     *   structuralErrors: list<string>,
     * }> $packages package short name => declaration data
     */
    private function __construct(private readonly array $packages)
    {
    }

    public static function load(string $root): self
    {
        $packages = [];
        $packageDirectories = glob($root . '/packages/*', GLOB_ONLYDIR) ?: [];
        sort($packageDirectories, SORT_STRING);
        foreach ($packageDirectories as $pkgDir) {
            $short = basename($pkgDir);
            $prefixes = self::readPsr4Prefixes($pkgDir . '/composer.json');
            $declarationPath = $pkgDir . '/' . self::DECLARATION_FILENAME;
            if (!is_file($declarationPath)) {
                continue;
            }
            $relativeFile = 'packages/' . $short . '/' . self::DECLARATION_FILENAME;
            $source = (string) file_get_contents($declarationPath);
            $packages[$short] = self::parseSource($short, $relativeFile, $prefixes, $source);
        }

        return new self($packages);
    }

    public static function loadAt(string $root, string $ref): self
    {
        [$listing, $exitCode] = self::gitRun($root, ['ls-tree', '-r', '--name-only', $ref, '--', 'packages']);
        if ($exitCode !== 0) {
            throw new RuntimeException("cannot list packages/ at {$ref}: " . trim($listing));
        }

        $composerPrefixCache = [];
        $packages = [];
        foreach (preg_split('/\R/', trim($listing)) ?: [] as $path) {
            if ($path === '' || !preg_match('#^packages/([^/]+)/' . preg_quote(self::DECLARATION_FILENAME, '#') . '$#', $path, $matches)) {
                continue;
            }
            $short = $matches[1];
            $composerPath = "packages/{$short}/composer.json";
            if (!isset($composerPrefixCache[$short])) {
                [$composerSource, $composerExit] = self::gitRun($root, ['show', "{$ref}:{$composerPath}"]);
                $composerPrefixCache[$short] = $composerExit === 0
                    ? self::extractPsr4Prefixes($composerSource)
                    : [];
            }

            [$source, $sourceExit] = self::gitRun($root, ['show', "{$ref}:{$path}"]);
            if ($sourceExit !== 0) {
                throw new RuntimeException("cannot read {$path} at {$ref}: " . trim($source));
            }
            $packages[$short] = self::parseSource($short, $path, $composerPrefixCache[$short], $source);
        }

        return new self($packages);
    }

    /**
     * The composed FQCN => disposition map. Only call after validate()
     * reports zero errors — with duplicates/contradictions present this
     * collapses to last-package-wins, which is exactly the silent breakage
     * §4 exists to prevent.
     *
     * @return array<string, string>
     */
    public function compose(): array
    {
        $map = [];
        foreach ($this->packages as $package) {
            foreach ($package['entries'] as $entry) {
                if (!is_string($entry['fqcn'] ?? null) || !is_string($entry['disposition'] ?? null)) {
                    continue;
                }
                $map[$entry['fqcn']] = $entry['disposition'];
            }
        }
        ksort($map, SORT_STRING);

        return $map;
    }

    /**
     * The package whose PSR-4 prefix is the longest match of $fqcn, across
     * every package this instance loaded — independent of which package (if
     * any) declared it.
     */
    public function owner(string $fqcn): ?string
    {
        $bestPackage = null;
        $bestLength = -1;
        foreach ($this->packages as $short => $package) {
            foreach ($package['prefixes'] as $prefix) {
                if ($prefix !== '' && str_starts_with($fqcn, $prefix) && strlen($prefix) > $bestLength) {
                    $bestPackage = $short;
                    $bestLength = strlen($prefix);
                }
            }
        }

        // A package may have declarations but own no PSR-4 prefix in the
        // loaded set (e.g. a fixture package with no composer.json entry, or
        // this instance was built from a subset of packages). Also check
        // packages with zero declarations but a composer.json prefix — they
        // are already included in $this->packages keyed by directory scan.
        return $bestPackage;
    }

    /**
     * Validate the declaration plane per §4. Returns a list of human-readable
     * error strings, empty when the plane is valid. $scanner supplies the
     * real source-of-truth for "does this FQCN load" and "which contract
     * shapes exist" (missing check).
     *
     * @return list<string>
     */
    public function validate(SurfaceScanner $scanner): array
    {
        $errors = [];
        foreach ($this->packages as $package) {
            $errors = array_merge($errors, $package['structuralErrors']);
        }
        if ($errors !== []) {
            // Structural errors mean entries/notes cannot be trusted; stop
            // here rather than compound them with ownership/duplicate noise.
            return $errors;
        }

        // duplicate: same FQCN twice within one package file.
        foreach ($this->packages as $short => $package) {
            $seen = [];
            foreach ($package['entries'] as $index => $entry) {
                $fqcn = $entry['fqcn'];
                if (isset($seen[$fqcn])) {
                    $errors[] = sprintf(
                        'duplicate: %s is declared twice in %s (indices %d and %d).',
                        $fqcn,
                        $package['file'],
                        $seen[$fqcn],
                        $index,
                    );
                    continue;
                }
                $seen[$fqcn] = $index;
            }
        }

        // Where is each FQCN declared, across every package (for contradiction + orphaned).
        /** @var array<string, list<array{package: string, file: string, index: int, disposition: string}>> $declaredBy */
        $declaredBy = [];
        foreach ($this->packages as $short => $package) {
            foreach ($package['entries'] as $index => $entry) {
                $declaredBy[$entry['fqcn']][] = [
                    'package' => $short,
                    'file' => $package['file'],
                    'index' => $index,
                    'disposition' => $entry['disposition'],
                ];
            }
        }

        // contradictory: declared by more than one package (even identically).
        foreach ($declaredBy as $fqcn => $declarations) {
            if (count($declarations) < 2) {
                continue;
            }
            $descriptions = array_map(
                static fn(array $d): string => sprintf('%s (%s, index %d, disposition %s)', $d['package'], $d['file'], $d['index'], $d['disposition']),
                $declarations,
            );
            $errors[] = sprintf(
                'contradictory: %s is declared by more than one package — %s. One declaration plane means one declaration.',
                $fqcn,
                implode('; ', $descriptions),
            );
        }

        // orphaned: wrong owner, or does not load.
        foreach ($this->packages as $short => $package) {
            foreach ($package['entries'] as $index => $entry) {
                $fqcn = $entry['fqcn'];
                $ownedByDeclaringPackage = false;
                foreach ($package['prefixes'] as $prefix) {
                    if ($prefix !== '' && str_starts_with($fqcn, $prefix)) {
                        $ownedByDeclaringPackage = true;
                        break;
                    }
                }
                if (!$ownedByDeclaringPackage) {
                    $actualOwner = $this->owner($fqcn);
                    $errors[] = sprintf(
                        'orphaned: %s declared in %s (package %s) is not owned by that package\'s PSR-4 prefix%s.',
                        $fqcn,
                        $package['file'],
                        $short,
                        $actualOwner !== null ? " (owned by package {$actualOwner})" : '',
                    );
                    continue;
                }
                if ($scanner->shape($fqcn) === null) {
                    $errors[] = sprintf(
                        'orphaned: %s declared in %s (package %s) does not load — no matching interface, class, trait, or enum was found.',
                        $fqcn,
                        $package['file'],
                        $short,
                    );
                }
            }
        }

        // missing: a scanned contract shape with no declaration anywhere.
        $declaredFqcns = array_fill_keys(array_keys($declaredBy), true);
        $missing = [];
        foreach ($scanner->contractShapes() as $fqcn) {
            if (!isset($declaredFqcns[$fqcn])) {
                $missing[] = $fqcn;
            }
        }
        if ($missing !== []) {
            sort($missing, SORT_STRING);
            $errors[] = sprintf(
                "missing: %d contract shape(s) discovered in source have no declaration in their owning package's public-surface.php:\n  %s",
                count($missing),
                implode("\n  ", $missing),
            );
        }

        return $errors;
    }

    /**
     * @return array<string, array{entries: list<array{fqcn: string, disposition: string, purpose: ?string, ref: ?string}>, notes: list<string>, prefixes: list<string>}>
     */
    public function packages(): array
    {
        $result = [];
        foreach ($this->packages as $short => $package) {
            $entries = [];
            foreach ($package['entries'] as $entry) {
                $entries[] = [
                    'fqcn' => $entry['fqcn'],
                    'disposition' => $entry['disposition'],
                    'purpose' => $entry['purpose'] ?? null,
                    'ref' => $entry['ref'] ?? null,
                ];
            }
            $result[$short] = [
                'entries' => $entries,
                'notes' => $package['notes'],
                'prefixes' => $package['prefixes'],
            ];
        }

        return $result;
    }

    /** @return list<string> */
    private static function readPsr4Prefixes(string $composerJsonPath): array
    {
        if (!is_file($composerJsonPath)) {
            return [];
        }

        return self::extractPsr4Prefixes((string) file_get_contents($composerJsonPath));
    }

    /** @return list<string> */
    private static function extractPsr4Prefixes(string $composerJsonSource): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($composerJsonSource, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $psr4 = $decoded['autoload']['psr-4'] ?? null;
        if (!is_array($psr4)) {
            return [];
        }

        return array_values(array_filter(array_keys($psr4), 'is_string'));
    }

    /**
     * @param list<string> $prefixes
     * @return array{dir: string, file: string, prefixes: list<string>, entries: list<array{fqcn: mixed, disposition: mixed, purpose: mixed, ref: mixed}>, notes: list<mixed>, structuralErrors: list<string>}
     */
    private static function parseSource(string $short, string $relativeFile, array $prefixes, string $source): array
    {
        $empty = ['dir' => "packages/{$short}", 'file' => $relativeFile, 'prefixes' => $prefixes, 'entries' => [], 'notes' => [], 'structuralErrors' => []];

        $temporary = tempnam(sys_get_temp_dir(), 'waaseyaa-surface-decl-');
        if ($temporary === false) {
            $empty['structuralErrors'][] = "invalid: could not materialize {$relativeFile} for evaluation.";

            return $empty;
        }
        file_put_contents($temporary, $source);
        try {
            /** @var mixed $decoded */
            $decoded = require $temporary;
        } catch (\Throwable $e) {
            @unlink($temporary);
            $empty['structuralErrors'][] = "invalid: {$relativeFile} raised {$e->getMessage()} while loading.";

            return $empty;
        }
        @unlink($temporary);

        if (!is_array($decoded)) {
            $empty['structuralErrors'][] = "invalid: {$relativeFile} must return an array.";

            return $empty;
        }

        $errors = [];
        $entries = [];
        $rawEntries = $decoded['entries'] ?? [];
        if (!is_array($rawEntries) || !array_is_list($rawEntries)) {
            $errors[] = "invalid: {$relativeFile} 'entries' must be a list (sequential array), not a keyed map.";
        } else {
            foreach ($rawEntries as $index => $rawEntry) {
                if (!is_array($rawEntry)) {
                    $errors[] = "invalid: {$relativeFile} entry at index {$index} must be an array.";
                    continue;
                }
                $fqcn = $rawEntry['fqcn'] ?? null;
                $disposition = $rawEntry['disposition'] ?? null;
                $purpose = $rawEntry['purpose'] ?? null;
                $ref = $rawEntry['ref'] ?? null;
                if (!is_string($fqcn) || $fqcn === '') {
                    $errors[] = "invalid: {$relativeFile} entry at index {$index} has a missing or non-string 'fqcn'.";
                    continue;
                }
                if (!is_string($disposition) || !in_array($disposition, self::ALLOWED_DISPOSITIONS, true)) {
                    $errors[] = sprintf(
                        "invalid: %s entry at index %d (%s) has disposition %s; allowed: %s.",
                        $relativeFile,
                        $index,
                        $fqcn,
                        is_string($disposition) ? "'{$disposition}'" : var_export($disposition, true),
                        implode('|', self::ALLOWED_DISPOSITIONS),
                    );
                    continue;
                }
                if ($purpose !== null && !is_string($purpose)) {
                    $errors[] = "invalid: {$relativeFile} entry at index {$index} ({$fqcn}) has a non-string 'purpose'.";
                    continue;
                }
                if ($ref !== null && !is_string($ref)) {
                    $errors[] = "invalid: {$relativeFile} entry at index {$index} ({$fqcn}) has a non-string 'ref'.";
                    continue;
                }
                $entries[$index] = ['fqcn' => $fqcn, 'disposition' => $disposition, 'purpose' => $purpose, 'ref' => $ref];
            }
        }

        $notes = [];
        $rawNotes = $decoded['notes'] ?? [];
        if (!is_array($rawNotes) || !array_is_list($rawNotes)) {
            $errors[] = "invalid: {$relativeFile} 'notes' must be a list (sequential array), not a keyed map.";
        } else {
            foreach ($rawNotes as $index => $note) {
                if (!is_string($note)) {
                    $errors[] = "invalid: {$relativeFile} note at index {$index} must be a string.";
                    continue;
                }
                $notes[] = $note;
            }
        }

        $unknownKeys = array_diff(array_keys($decoded), ['entries', 'notes']);
        if ($unknownKeys !== []) {
            $errors[] = "invalid: {$relativeFile} has unknown top-level key(s): " . implode(', ', $unknownKeys) . '.';
        }

        return [
            'dir' => "packages/{$short}",
            'file' => $relativeFile,
            'prefixes' => $prefixes,
            'entries' => array_values($entries),
            'notes' => $notes,
            'structuralErrors' => $errors,
        ];
    }

    /** @param list<string> $arguments @return array{string, int} */
    private static function gitRun(string $root, array $arguments): array
    {
        $bash = 'bash';
        if (PHP_OS_FAMILY === 'Windows') {
            $whereOutput = shell_exec('where git 2>NUL');
            $gitExecutables = preg_split('/\R/', (string) $whereOutput);
            foreach ($gitExecutables === false ? [] : $gitExecutables as $gitExecutable) {
                $candidate = dirname(dirname($gitExecutable)) . '/bin/bash.exe';
                if ($gitExecutable !== '' && is_file($candidate)) {
                    $bash = $candidate;
                    break;
                }
            }
        }
        $command = array_merge([$bash, $root . '/bin/git', '-C', $root], $arguments);
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return ['', 127];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [(string) $stdout . ($exitCode === 0 ? '' : (string) $stderr), $exitCode];
    }
}
