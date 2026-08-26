<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling;

use RuntimeException;

/** Pure source-record and delegation boundary for first-party consumer launchers. */
final class DevRuntimeConsumer
{
    /** @var list<string> */
    private const array CONSUMER_FILES = [
        'bin/dev-runtime',
        'tools/lib/DevRuntimeConsumer.php',
    ];

    /** @var list<string> */
    private const array AUTHORITY_FILES = [
        'bin/dev-runtime',
        'bin/git',
        'tools/lib/DevRuntime.php',
        'tools/dev-runtime-manifest.json',
        'tools/frankenphp-runtime-pin.json',
    ];

    /** @return array<string, mixed> */
    public static function loadSourceRecord(string $path, string $consumerRoot): array
    {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException("cannot read dev runtime source record: {$path}");
        }
        $record = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($record) || array_is_list($record)) {
            throw new RuntimeException('dev runtime source record must be a JSON object');
        }
        self::requireExactKeys(
            $record,
            ['schema_version', 'change_record', 'repository', 'commit', 'consumer_files', 'authority_files'],
            'source record',
        );
        if (($record['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('dev runtime source record schema_version must be 1');
        }
        if (($record['change_record'] ?? null) !== 'FW-DEV-RUNTIME-01') {
            throw new RuntimeException('dev runtime source record must name FW-DEV-RUNTIME-01');
        }
        if (($record['repository'] ?? null) !== 'waaseyaa/framework') {
            throw new RuntimeException('dev runtime source repository must be waaseyaa/framework');
        }
        if (!is_string($record['commit'] ?? null) || preg_match('/^[0-9a-f]{40}$/D', $record['commit']) !== 1) {
            throw new RuntimeException('dev runtime source commit must be a 40-character lowercase commit');
        }

        $consumerFiles = self::hashMap($record['consumer_files'] ?? null, self::CONSUMER_FILES, 'consumer_files');
        $authorityFiles = self::hashMap($record['authority_files'] ?? null, self::AUTHORITY_FILES, 'authority_files');
        foreach ($consumerFiles as $relative => $sha256) {
            $local = rtrim($consumerRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($local) || !hash_equals($sha256, (string) hash_file('sha256', $local))) {
                throw new RuntimeException("consumer file checksum mismatch: {$relative}");
            }
        }

        $record['consumer_files'] = $consumerFiles;
        $record['authority_files'] = $authorityFiles;

        return $record;
    }

    /** @param array<string, mixed> $record */
    public static function cachePath(string $cacheRoot, array $record): string
    {
        $identity = [
            'schema_version' => $record['schema_version'] ?? null,
            'change_record' => $record['change_record'] ?? null,
            'repository' => $record['repository'] ?? null,
            'commit' => $record['commit'] ?? null,
            'authority_files' => $record['authority_files'] ?? null,
        ];
        $bytes = json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return rtrim($cacheRoot, '/\\') . '/waaseyaa/dev-runtime-source/' . hash('sha256', $bytes);
    }

    /** @param array<string, string> $authorityFiles @return list<string> */
    public static function sourceErrors(string $cache, array $authorityFiles): array
    {
        $errors = [];
        foreach ($authorityFiles as $relative => $sha256) {
            $path = rtrim($cache, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path)) {
                $errors[] = "canonical source is missing: {$relative}";
            } elseif (!hash_equals($sha256, (string) hash_file('sha256', $path))) {
                $errors[] = "canonical source checksum mismatch: {$relative}";
            }
        }

        return $errors;
    }

    public static function sourceUrl(string $repository, string $commit, string $relative): string
    {
        if ($repository !== 'waaseyaa/framework'
            || preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1
            || !in_array($relative, self::AUTHORITY_FILES, true)) {
            throw new RuntimeException('refusing an ungoverned dev runtime source URL');
        }

        return "https://raw.githubusercontent.com/{$repository}/{$commit}/{$relative}";
    }

    /** @param list<string> $arguments @return non-empty-list<string> */
    public static function delegationCommand(string $cache, string $consumerRoot, array $arguments): array
    {
        if ($arguments === []) {
            return [PHP_BINARY, rtrim($cache, '/\\') . '/bin/dev-runtime'];
        }
        array_splice($arguments, 1, 0, ['--repository-root=' . rtrim($consumerRoot, '/\\')]);

        return [PHP_BINARY, rtrim($cache, '/\\') . '/bin/dev-runtime', ...$arguments];
    }

    /** @param mixed $value @param list<string> $expected @return array<string, string> */
    private static function hashMap(mixed $value, array $expected, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException("{$path} must be an object");
        }
        self::requireExactKeys($value, $expected, $path);
        foreach ($value as $relative => $sha256) {
            if (!is_string($sha256) || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
                throw new RuntimeException("{$path}.{$relative} must be a lowercase SHA-256");
            }
        }

        /** @var array<string, string> $value */
        return $value;
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private static function requireExactKeys(array $value, array $expected, string $path): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException("{$path} keys differ");
        }
    }
}
