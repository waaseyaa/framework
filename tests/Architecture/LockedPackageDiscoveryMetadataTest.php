<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `composer.lock` must carry the same `extra.waaseyaa` discovery metadata the
 * package's own `composer.json` declares.
 *
 * **Why this is a gate and not a convention.** `composer install` — what CI
 * and every consumer run — builds `vendor/composer/installed.json` from the
 * LOCK, not by re-reading each path package's `composer.json`; only
 * `composer update` re-reads the sources. `ProviderDiscovery` and
 * `PackageManifestCompiler` then read `extra.waaseyaa` out of that
 * `installed.json`. So a `composer.json` that gains a provider without a lock
 * refresh produces a repository where:
 *
 *  - every local run works, because the developer's `vendor/` was written by
 *    the `composer update` that made them notice at all; and
 *  - every packaged install silently omits the provider — no error, no
 *    warning, just a command that does not exist.
 *
 * That is not hypothetical: #2659 shipped `McpStdioServiceProvider` in
 * `packages/cli/composer.json` with a lock whose `extra` snapshot predated it,
 * and CI's own subprocess proof failed with `There are no commands defined in
 * the "mcp" namespace` while the same test passed on every developer machine.
 * The failure is invisible in review — the lock diff for such a change touches
 * dependency constraints, and the missing `extra` hunk looks like nothing at
 * all.
 *
 * The whole `extra.waaseyaa` block is compared, not just `providers`, because
 * discovery reads `policies`, `migrations`, `permissions`, and
 * `config-contract` from exactly the same place and inherits exactly the same
 * staleness.
 *
 * **The fix is always the same:** `composer update --lock`.
 */
#[CoversNothing]
final class LockedPackageDiscoveryMetadataTest extends TestCase
{
    #[Test]
    public function every_first_party_package_declares_the_same_discovery_metadata_in_composer_lock(): void
    {
        $repoRoot = \dirname(__DIR__, 2);
        $locked = self::lockedExtras($repoRoot);

        $drifted = [];
        $detail = [];
        $compared = 0;

        foreach (glob($repoRoot . '/packages/*/composer.json') ?: [] as $manifestPath) {
            $manifest = self::readJson($manifestPath);
            $name = $manifest['name'] ?? null;
            if (!\is_string($name) || !\array_key_exists($name, $locked)) {
                // A package the root manifest does not resolve (a metapackage
                // consumers require directly, say) has no lock entry to drift
                // against.
                continue;
            }

            ++$compared;
            $onDisk = self::normalize($manifest['extra']['waaseyaa'] ?? null);
            $inLock = self::normalize($locked[$name]['waaseyaa'] ?? null);

            if ($onDisk !== $inLock) {
                $drifted[] = $name;
                $detail[] = '  ' . $name . "\n" . self::describeDrift($onDisk, $inLock);
            }
        }

        self::assertGreaterThan(0, $compared, 'No first-party package manifests were compared — the glob or the lock shape changed.');
        self::assertSame(
            [],
            $drifted,
            "composer.lock's extra.waaseyaa snapshot has drifted from the package sources.\n"
            . "A packaged `composer install` reads the LOCK, so anything missing below is absent in CI\n"
            . "and for consumers while every local run keeps working. Fix: composer update --lock\n\n"
            . implode("\n", $detail),
        );
    }

    /**
     * A compact, per-key account of the drift — the full blocks are long
     * enough (26 provider FQCNs on `waaseyaa/cli` alone) that dumping both
     * sides buries the one entry that actually differs.
     */
    private static function describeDrift(mixed $onDisk, mixed $inLock): string
    {
        $onDisk = \is_array($onDisk) ? $onDisk : [];
        $inLock = \is_array($inLock) ? $inLock : [];

        $lines = [];
        foreach (array_keys($onDisk + $inLock) as $key) {
            $disk = $onDisk[$key] ?? null;
            $lock = $inLock[$key] ?? null;
            if ($disk === $lock) {
                continue;
            }

            if (\is_array($disk) && \is_array($lock) && array_is_list($disk) && array_is_list($lock)) {
                $lines[] = \sprintf(
                    '    %s: only in composer.json: [%s]; only in composer.lock: [%s]',
                    $key,
                    implode(', ', array_diff($disk, $lock)),
                    implode(', ', array_diff($lock, $disk)),
                );

                continue;
            }

            $lines[] = \sprintf(
                '    %s: composer.json=%s composer.lock=%s',
                $key,
                json_encode($disk, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                json_encode($lock, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * The locked `extra` block for every first-party package, dev included —
     * `installed.json` is written from both halves of the lock.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function lockedExtras(string $repoRoot): array
    {
        $lock = self::readJson($repoRoot . '/composer.lock');

        $extras = [];
        foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
            $name = $package['name'] ?? null;
            if (!\is_string($name) || !str_starts_with($name, 'waaseyaa/')) {
                continue;
            }
            $extras[$name] = \is_array($package['extra'] ?? null) ? $package['extra'] : [];
        }

        return $extras;
    }

    /**
     * Recursively sort map keys so a re-serialization that reorders keys is not
     * reported as drift, while leaving list order — which IS meaningful for
     * `providers` — exactly as declared.
     *
     * The `{}` / `[]` distinction needs no handling: `json_decode(…, true)`
     * has already collapsed both onto the same empty PHP array on either side
     * of the comparison.
     */
    private static function normalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $normalized = array_map(self::normalize(...), $value);
        if (!array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    private static function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw, 'Unreadable: ' . $path);

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'Not a JSON object: ' . $path);

        return $decoded;
    }
}
