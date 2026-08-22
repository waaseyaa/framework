<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling;

/**
 * Parses the machine-verifiable public-surface directives in CHANGELOG.md.
 *
 * Only directives newly added by the candidate under the canonical
 * ## [Unreleased] section carry authority. Historical release sections and
 * pre-existing Unreleased text are evidence, not authorization.
 */
final class SurfaceChangeAuthorization
{
    private const FQCN = '[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+';

    /**
     * @param list<string> $addedLines Lines added to CHANGELOG.md by the candidate.
     *
     * @return array{
     *   removals: array<string, true>,
     *   deprecations: array<string, true>,
     *   renames: array<string, string>,
     *   errors: list<string>
     * }
     */
    public static function parse(string $changelog, array $addedLines): array
    {
        $added = array_fill_keys($addedLines, true);
        $result = [
            'removals' => [],
            'deprecations' => [],
            'renames' => [],
            'errors' => [],
        ];

        $inUnreleased = false;
        $section = null;
        $changelogLines = preg_split('/\R/', $changelog);
        foreach ($changelogLines === false ? [] : $changelogLines as $offset => $line) {
            $lineNumber = $offset + 1;
            if (str_starts_with($line, '## ')) {
                $inUnreleased = $line === '## [Unreleased]';
                $section = null;
                continue;
            }
            if ($inUnreleased && str_starts_with($line, '### ')) {
                $section = substr($line, 4);
                continue;
            }

            if (!$inUnreleased || !isset($added[$line]) || !str_starts_with($line, '- Public surface ')) {
                continue;
            }

            if (preg_match('/^- Public surface removal: `(' . self::FQCN . ')`$/', $line, $matches) === 1) {
                if ($section !== 'Removed') {
                    $result['errors'][] = self::wrongSection($lineNumber, 'removal', 'Removed');
                    continue;
                }
                self::recordSingle($result['removals'], $matches[1], 'removal', $lineNumber, $result['errors']);
                continue;
            }

            if (preg_match('/^- Public surface deprecation: `(' . self::FQCN . ')`$/', $line, $matches) === 1) {
                if ($section !== 'Deprecated') {
                    $result['errors'][] = self::wrongSection($lineNumber, 'deprecation', 'Deprecated');
                    continue;
                }
                self::recordSingle($result['deprecations'], $matches[1], 'deprecation', $lineNumber, $result['errors']);
                continue;
            }

            if (preg_match('/^- Public surface rename: `(' . self::FQCN . ')` -> `(' . self::FQCN . ')`$/', $line, $matches) === 1) {
                if ($section !== 'Removed') {
                    $result['errors'][] = self::wrongSection($lineNumber, 'rename', 'Removed');
                    continue;
                }
                [$old, $new] = [$matches[1], $matches[2]];
                if ($old === $new) {
                    $result['errors'][] = "line {$lineNumber}: a public-surface rename must change the FQCN.";
                    continue;
                }
                if (isset($result['renames'][$old]) || isset($result['removals'][$old])) {
                    $result['errors'][] = "line {$lineNumber}: duplicate or conflicting removal authorization for {$old}.";
                    continue;
                }
                $result['renames'][$old] = $new;
                continue;
            }

            $result['errors'][] = "line {$lineNumber}: malformed public-surface directive; use the documented exact syntax.";
        }

        foreach (array_keys($result['removals']) as $fqcn) {
            if (isset($result['renames'][$fqcn])) {
                $result['errors'][] = "conflicting removal and rename authorization for {$fqcn}.";
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $baseMap
     * @param array<string, string> $candidateMap
     *
     * @return list<string>
     */
    public static function removedMapEntries(array $baseMap, array $candidateMap): array
    {
        $removed = array_values(array_diff(array_keys($baseMap), array_keys($candidateMap)));
        sort($removed);

        return $removed;
    }

    /**
     * @param array<string, string> $baseMap
     * @param array<string, string> $candidateMap
     *
     * @return list<string>
     */
    public static function publicDowngrades(array $baseMap, array $candidateMap): array
    {
        $downgrades = [];
        foreach ($baseMap as $fqcn => $disposition) {
            if ($disposition === 'public' && isset($candidateMap[$fqcn]) && $candidateMap[$fqcn] !== 'public') {
                $downgrades[] = $fqcn;
            }
        }
        sort($downgrades);

        return $downgrades;
    }

    /** @param array<string, true> $records */
    private static function recordSingle(array &$records, string $fqcn, string $kind, int $lineNumber, array &$errors): void
    {
        if (isset($records[$fqcn])) {
            $errors[] = "line {$lineNumber}: duplicate public-surface {$kind} authorization for {$fqcn}.";
            return;
        }
        $records[$fqcn] = true;
    }

    private static function wrongSection(int $lineNumber, string $kind, string $required): string
    {
        return "line {$lineNumber}: public-surface {$kind} authorization must be under ### {$required}.";
    }
}
