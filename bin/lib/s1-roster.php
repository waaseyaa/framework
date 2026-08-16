<?php

declare(strict_types=1);

/**
 * Shared S1 roster scanner — schema v2 semantic identity.
 *
 * Used by the four S1 recorded-roster verifiers
 * (bin/check-s1-{configuration-activation,configuration-authority,
 * schema-authority,sqlite-contract}). One implementation owns the identity
 * rules of docs/specs/governed-gates.md §6: a roster entry binds
 * {path, pattern, class, match_sha256, occurrence} — the semantic content of
 * an occurrence — and never positional data (line numbers, line hashes,
 * whole-file hashes), so unrelated edits in rostered files cannot invalidate
 * a roster. Line numbers are derived at report time for failure output only.
 *
 * Plain functions, no autoloader: the verifiers must run pre-`composer install`.
 */

/**
 * Trees that are never repository content: excluded from every scan so a
 * local run and a CI run of the same gate agree on the same tree
 * (governed-gates.md invariant 5). vendor/ and node_modules/ are excluded at
 * any depth (nested package vendors are gitignored build artifacts that
 * poison local-only scans).
 */
function s1RosterIsExcluded(string $relative): bool
{
    if (str_starts_with($relative, '.git/')
        || str_starts_with($relative, 'storage/')
        || str_starts_with($relative, 'tmp/')
    ) {
        return true;
    }

    return str_starts_with($relative, 'vendor/')
        || str_contains($relative, '/vendor/')
        || str_starts_with($relative, 'node_modules/')
        || str_contains($relative, '/node_modules/');
}

/**
 * Identity hash of a matched text: lowercased with all whitespace removed —
 * formatting churn inside a match yields one identity. Matches are short
 * pattern hits (symbols, tokens, call heads) whose patterns already impose
 * word boundaries, so whitespace carries no semantic weight inside them.
 */
function s1RosterMatchHash(string $matched): string
{
    return hash('sha256', strtolower((string) preg_replace('/\s+/', '', $matched)));
}

/**
 * Scan subtrees for pattern occurrences.
 *
 * Returned entries carry the canonical identity keys PLUS a transient 'line'
 * key (live position, for report-time display only) — pass through
 * s1RosterCanonicalize() before recording or comparing.
 *
 * @param list<string> $scanRoots Relative subtrees to walk ('' = repo root).
 * @param array<string, string> $patterns patternId => PCRE.
 * @param callable(string, string): bool $includeFile (relativePath, contents) => scan this file?
 * @param callable(string, string): string $classify (relativePath, patternId) => class label.
 * @param list<string> $extraFiles Relative paths scanned in addition to the walk.
 * @return list<array{path: string, pattern: string, class: string, match_sha256: string, occurrence: int, line: int}>
 */
function s1RosterScan(string $root, array $scanRoots, array $patterns, callable $includeFile, callable $classify, array $extraFiles = []): array
{
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
    $files = [];

    foreach ($scanRoots as $scanRoot) {
        $base = $scanRoot === '' ? $normalizedRoot : $normalizedRoot . '/' . $scanRoot;
        if (!is_dir($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr($path, strlen($normalizedRoot) + 1);
            if (s1RosterIsExcluded($relative)) {
                continue;
            }
            $files[$relative] = true;
        }
    }
    foreach ($extraFiles as $relative) {
        if (is_file($normalizedRoot . '/' . $relative)) {
            $files[$relative] = true;
        }
    }

    $entries = [];
    foreach (array_keys($files) as $relative) {
        $contents = @file_get_contents($normalizedRoot . '/' . $relative);
        if ($contents === false) {
            continue;
        }
        if (!$includeFile($relative, $contents)) {
            continue;
        }

        // Collect matches in offset order so occurrence indices are stable.
        $fileMatches = [];
        foreach ($patterns as $patternId => $pattern) {
            preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$matched, $offset]) {
                $fileMatches[] = [
                    'pattern' => $patternId,
                    'match_sha256' => s1RosterMatchHash($matched),
                    'offset' => $offset,
                ];
            }
        }
        usort($fileMatches, static fn(array $left, array $right): int => $left['offset'] <=> $right['offset']);

        $occurrenceCounters = [];
        foreach ($fileMatches as $match) {
            $key = $match['pattern'] . '|' . $match['match_sha256'];
            $occurrenceCounters[$key] = ($occurrenceCounters[$key] ?? 0) + 1;
            $entries[] = [
                'path' => $relative,
                'pattern' => $match['pattern'],
                'class' => $classify($relative, $match['pattern']),
                'match_sha256' => $match['match_sha256'],
                'occurrence' => $occurrenceCounters[$key],
                'line' => substr_count(substr($contents, 0, $match['offset']), "\n") + 1,
            ];
        }
    }

    return $entries;
}

/**
 * Strip transient display data and impose the canonical order. The result is
 * what rosters record and what verifiers compare.
 *
 * @param list<array<string, int|string>> $entries
 * @return list<array{path: string, pattern: string, class: string, match_sha256: string, occurrence: int}>
 */
function s1RosterCanonicalize(array $entries): array
{
    $canonical = array_map(static fn(array $entry): array => [
        'path' => (string) $entry['path'],
        'pattern' => (string) $entry['pattern'],
        'class' => (string) $entry['class'],
        'match_sha256' => (string) $entry['match_sha256'],
        'occurrence' => (int) $entry['occurrence'],
    ], $entries);
    usort($canonical, static fn(array $left, array $right): int => [
        $left['path'], $left['pattern'], $left['match_sha256'], $left['occurrence'],
    ] <=> [$right['path'], $right['pattern'], $right['match_sha256'], $right['occurrence']]);

    return $canonical;
}

/**
 * Assemble the recorded-roster document (schema v2).
 *
 * @param array<string, string> $patterns
 * @param list<string>|null $allowedClasses Omitted from the document when null.
 * @param list<array<string, int|string>> $canonicalEntries
 * @return array<string, mixed>
 */
function s1RosterDocument(string $queryVersion, array $patterns, ?array $allowedClasses, array $canonicalEntries): array
{
    $document = [
        'schema_version' => 2,
        'query_version' => $queryVersion,
        'patterns' => $patterns,
    ];
    if ($allowedClasses !== null) {
        $document['allowed_classes'] = $allowedClasses;
    }
    $document['candidates'] = $canonicalEntries;

    return $document;
}

/**
 * Human-readable difference between a recorded candidate list and a live scan,
 * each line precise enough to act on, ending with the exact repair command
 * (governed-gates.md invariant 3). Live line numbers are derived from the
 * transient scan — never from the roster.
 *
 * @param list<array<string, int|string>> $recordedCandidates Canonical entries.
 * @param list<array<string, int|string>> $liveTransient Entries from s1RosterScan (with 'line').
 * @return list<string> Empty when identical.
 */
function s1RosterDiff(array $recordedCandidates, array $liveTransient, string $repairFlag): array
{
    $keyOf = static fn(array $entry): string => implode('|', [
        $entry['path'], $entry['pattern'], $entry['class'], $entry['match_sha256'], $entry['occurrence'],
    ]);

    $recordedKeys = [];
    foreach (s1RosterCanonicalize($recordedCandidates) as $entry) {
        $recordedKeys[$keyOf($entry)] = $entry;
    }
    $liveKeys = [];
    foreach ($liveTransient as $entry) {
        $liveKeys[$keyOf($entry)] = $entry;
    }

    $lines = [];
    foreach ($liveKeys as $key => $entry) {
        if (!isset($recordedKeys[$key])) {
            $lines[] = sprintf(
                'new candidate not in roster: %s:%d pattern=%s class=%s occurrence=%d',
                $entry['path'],
                $entry['line'],
                $entry['pattern'],
                $entry['class'],
                $entry['occurrence'],
            );
        }
    }
    foreach ($recordedKeys as $key => $entry) {
        if (!isset($liveKeys[$key])) {
            $lines[] = sprintf(
                'recorded candidate no longer present: %s pattern=%s class=%s occurrence=%d',
                $entry['path'],
                $entry['pattern'],
                $entry['class'],
                $entry['occurrence'],
            );
        }
    }

    if ($lines !== []) {
        $lines[] = sprintf('repair: review the changes above, then regenerate with %s and commit the roster.', $repairFlag);
    }

    return $lines;
}
