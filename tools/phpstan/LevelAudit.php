<?php

declare(strict_types=1);

namespace Waaseyaa\Tools\PHPStan;

final class LevelAudit
{
    /** @return list<int> */
    public static function parseLevels(string $value): array
    {
        if (preg_match('/^(\d+)-(\d+)$/', $value, $matches) === 1) {
            $start = (int) $matches[1];
            $end = (int) $matches[2];
            if ($start > $end) {
                throw new \InvalidArgumentException('Level ranges must be ascending.');
            }
            $levels = range($start, $end);
        } elseif (preg_match('/^\d+(?:,\d+)*$/', $value) === 1) {
            $levels = array_map(static fn (string $level): int => (int) $level, explode(',', $value));
        } else {
            throw new \InvalidArgumentException('Levels must be comma-separated integers or an ascending range.');
        }

        $levels = array_values(array_unique($levels));
        sort($levels, SORT_NUMERIC);
        if ($levels === [] || min($levels) < 0 || max($levels) > 10) {
            throw new \InvalidArgumentException('Levels must be a non-empty subset of 0 through 10.');
        }

        return $levels;
    }

    /**
     * @param list<int> $levels
     * @param array<string, string> $configurationFiles path => sha256
     */
    public static function inputIdentity(
        array $levels,
        string $phpstanVersion,
        string $expandedParameters,
        string $root,
        array $configurationFiles,
    ): string {
        sort($levels, SORT_NUMERIC);
        ksort($configurationFiles, SORT_STRING);

        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedParameters = str_replace('\\', '/', $expandedParameters);
        $normalizedParameters = str_replace($normalizedRoot, '{repo}', $normalizedParameters);

        return hash('sha256', self::canonicalJson([
            'levels' => $levels,
            'phpstan_version' => trim($phpstanVersion),
            'expanded_parameters' => $normalizedParameters,
            'configuration_files' => $configurationFiles,
        ]));
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    public static function assertComparable(array $expected, array $actual): void
    {
        $expectedIdentity = $expected['input_identity_sha256'] ?? null;
        $actualIdentity = $actual['input_identity_sha256'] ?? null;
        if (!is_string($expectedIdentity) || !is_string($actualIdentity)) {
            throw new \RuntimeException('Both evidence documents must contain input_identity_sha256.');
        }
        if (!hash_equals($expectedIdentity, $actualIdentity)) {
            throw new \RuntimeException('PHPStan measurement inputs changed; record and review a new evidence document.');
        }
    }

    /**
     * @param list<array{file:string, identifier:?string}> $findings
     *
     * @return array{total:int, by_identifier:array<string, int>, by_package:array<string, int>}
     */
    public static function summarize(array $findings, string $root): array
    {
        $byIdentifier = [];
        $byPackage = [];
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

        foreach ($findings as $finding) {
            $identifier = $finding['identifier'] ?? 'unknown';
            if ($identifier === null || $identifier === '') {
                $identifier = 'unknown';
            }
            $byIdentifier[$identifier] = ($byIdentifier[$identifier] ?? 0) + 1;

            $file = str_replace('\\', '/', $finding['file']);
            $relative = str_starts_with($file, $normalizedRoot . '/')
                ? substr($file, strlen($normalizedRoot) + 1)
                : $file;
            $package = '(root)';
            if (preg_match('#^packages/([^/]+)/#', $relative, $matches) === 1) {
                $package = $matches[1];
            }
            $byPackage[$package] = ($byPackage[$package] ?? 0) + 1;
        }

        ksort($byIdentifier, SORT_STRING);
        ksort($byPackage, SORT_STRING);

        return [
            'total' => count($findings),
            'by_identifier' => $byIdentifier,
            'by_package' => $byPackage,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function canonicalJson(array $value): string
    {
        self::sortAssociative($value);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<mixed> $value */
    private static function sortAssociative(array &$value): void
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortAssociative($item);
            }
        }
    }
}
