<?php

declare(strict_types=1);

/**
 * Fail-closed manifest loader for the random-order CI test selector
 * (docs/specs/ci-test-selection.md §3.3). Plain functions, no autoloader:
 * this must run before `vendor/` exists in some CI jobs.
 */

final class RosScopeFailure extends RuntimeException {}

/**
 * @return array{protected: list<string>, prefixes: array<string, list<string>>}
 */
function ros_load_manifest(string $root): array
{
    $path = $root . '/tools/random-order-scope-manifest.json';
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RosScopeFailure('scope manifest is unreadable: tools/random-order-scope-manifest.json');
    }

    try {
        $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RosScopeFailure('scope manifest is unparsable: ' . $exception->getMessage());
    }

    if (!is_array($document) || ($document['schema_version'] ?? null) !== 1) {
        throw new RosScopeFailure('scope manifest must declare schema_version 1');
    }

    $packages = [];
    foreach (glob($root . '/packages/*/composer.json') ?: [] as $manifestPath) {
        $packages[basename(dirname($manifestPath))] = true;
    }

    $prefixes = [];
    foreach ($document['prefixes'] ?? [] as $entry) {
        if (!is_array($entry) || !is_string($entry['path'] ?? null) || $entry['path'] === '') {
            throw new RosScopeFailure('scope manifest entry is missing a path');
        }
        if (!is_string($entry['rationale'] ?? null) || trim($entry['rationale']) === '') {
            throw new RosScopeFailure("scope manifest entry {$entry['path']} is missing a rationale");
        }
        $seeds = $entry['seeds'] ?? [];
        if (!is_array($seeds)) {
            throw new RosScopeFailure("scope manifest entry {$entry['path']} has a malformed seeds list");
        }
        foreach ($seeds as $seed) {
            if (!is_string($seed) || !isset($packages[$seed])) {
                throw new RosScopeFailure("scope manifest entry {$entry['path']} seeds an absent package");
            }
        }
        $prefixes[$entry['path']] = array_values($seeds);
    }

    // A directory prefix may host a longer exact-file entry, but two directory
    // prefixes where one contains the other make classification ambiguous.
    foreach (array_keys($prefixes) as $outer) {
        if (!str_ends_with($outer, '/')) {
            continue;
        }
        foreach (array_keys($prefixes) as $inner) {
            if ($inner !== $outer && str_ends_with($inner, '/') && str_starts_with($inner, $outer)) {
                throw new RosScopeFailure("ambiguous manifest prefix: {$inner} is shadowed by {$outer}");
            }
        }
    }

    $protected = $document['protected'] ?? [];
    if (!is_array($protected)) {
        throw new RosScopeFailure('scope manifest protected list is malformed');
    }
    foreach ($protected as $entry) {
        if (!is_string($entry) || $entry === '') {
            throw new RosScopeFailure('scope manifest protected list contains a malformed entry');
        }
    }

    ksort($prefixes);
    sort($protected);

    return ['protected' => array_values($protected), 'prefixes' => $prefixes];
}
