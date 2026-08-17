<?php

declare(strict_types=1);

/**
 * PHPUnit test inventory helpers shared by `bin/build-phpunit-shards`
 * (docs/specs/ci-test-selection.md). Plain functions, no autoloader: this
 * must run before `vendor/` exists in some CI jobs.
 *
 * This file previously also carried the fail-closed changed-package
 * selector (manifest loading, diff classification, consumer closure,
 * attribution, and selection-document assembly) removed after the
 * selector's measured saving (13%) turned out to rest on an undeclared
 * cross-package test-edge graph; with the graph honestly declared
 * (`bin/check-package-layers` PL010) the saving collapsed below CI
 * variance. Only the two functions the shard planner still needs — the
 * discovered test inventory and each path's atomic group — survive here.
 */

final class PhpUnitInventoryFailure extends RuntimeException {}

/**
 * The atomic group a path belongs to (docs/specs/ci-test-selection.md §1,
 * "the atomic group is the group `bin/build-phpunit-shards` already uses"):
 * `packages/<name>`, `tests/<TopDir>`, or `tests`.
 */
function ros_group_of(string $path): string
{
    if (preg_match('#^packages/([^/]+)/#', $path, $matches) === 1) {
        return 'packages/' . $matches[1];
    }
    if (preg_match('#^tests/([^/]+)/#', $path, $matches) === 1) {
        return 'tests/' . $matches[1];
    }

    return 'tests';
}

/**
 * Discovers the configured test inventory from phpunit.xml.dist. Fail-closed:
 * an unparsable config or an empty discovered inventory is a
 * PhpUnitInventoryFailure. Also fail-closed on a path assigned to more than
 * one testsuite — a live hazard, since `packages/analytics/tests` and
 * `packages/oauth-provider/tests` are whole-tree `Unit` directories that
 * would double-assign against `packages/*\/tests/Integration` if either
 * package gained an `Integration` subdirectory.
 *
 * @return array<string, string> inventory path => suite name
 */
function ros_inventory(string $root): array
{
    $previous = libxml_use_internal_errors(true);
    $config = simplexml_load_file($root . '/phpunit.xml.dist', options: LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_use_internal_errors($previous);
    if (!$config instanceof SimpleXMLElement) {
        throw new PhpUnitInventoryFailure('phpunit.xml.dist is unparsable');
    }

    $inventory = [];
    foreach ($config->testsuites->testsuite ?? [] as $suite) {
        $name = (string) $suite['name'];
        foreach ($suite->directory as $directory) {
            foreach (glob($root . '/' . trim((string) $directory), GLOB_ONLYDIR) ?: [] as $matched) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($matched, FilesystemIterator::SKIP_DOTS),
                );
                foreach ($iterator as $candidate) {
                    if (!$candidate instanceof SplFileInfo
                        || !$candidate->isFile()
                        || $candidate->isLink()
                        || !str_ends_with($candidate->getPathname(), 'Test.php')
                    ) {
                        continue;
                    }
                    $relative = substr(str_replace('\\', '/', $candidate->getPathname()), strlen($root) + 1);
                    if (isset($inventory[$relative]) && $inventory[$relative] !== $name) {
                        throw new PhpUnitInventoryFailure(
                            "test file is assigned to more than one suite: {$relative} "
                            . "(both \"{$inventory[$relative]}\" and \"{$name}\")",
                        );
                    }
                    $inventory[$relative] = $name;
                }
            }
        }
        foreach ($suite->file as $file) {
            $relative = trim((string) $file);
            if (isset($inventory[$relative]) && $inventory[$relative] !== $name) {
                throw new PhpUnitInventoryFailure(
                    "test file is assigned to more than one suite: {$relative} "
                    . "(both \"{$inventory[$relative]}\" and \"{$name}\")",
                );
            }
            $inventory[$relative] = $name;
        }
    }

    if ($inventory === []) {
        throw new PhpUnitInventoryFailure('phpunit.xml.dist discovered no test files');
    }
    ksort($inventory);

    return $inventory;
}
