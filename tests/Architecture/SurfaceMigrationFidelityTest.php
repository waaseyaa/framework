<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * §11 acceptance proof for #2901 (FW-DELIVERY-SURFACE-01): "every disposition
 * preserved; no broadening" — as a property that stays true forever, not a
 * one-time equality that the first legitimate surface change would break.
 *
 * The fixture is a verbatim snapshot of the last hand-authored
 * docs/public-surface-map.php (5bac44286). For every symbol it names, the
 * composed declaration plane must either still declare it with the same
 * disposition, or the change must be authorized by a charter §8.1 directive
 * (`- Public surface removal|rename|deprecation:`) somewhere in the compiled
 * CHANGELOG.md or a pending/released fragment. Anything else is a silent loss
 * or reclassification of a governed disposition.
 *
 * At the migration commit the composed plane reproduced the snapshot exactly
 * (719 = 719, no missing, no extra, no value differences); that evidence is
 * recorded in docs/change-records/FW-DELIVERY-SURFACE-01.md. This test reads
 * no git history, so it holds in shallow checkouts and random-order shards.
 */
#[CoversNothing]
final class SurfaceMigrationFidelityTest extends TestCase
{
    private const string SNAPSHOT = __DIR__ . '/fixtures/surface/pre-migration-public-surface-map.php';

    private const string DIRECTIVE = '/^- Public surface (?:removal|deprecation): `([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+)`$'
        . '|^- Public surface rename: `([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+)` -> `[^`]+`$/m';

    #[Test]
    public function no_pre_migration_disposition_is_silently_lost_or_reclassified(): void
    {
        $root = dirname(__DIR__, 2);

        /** @var array<string, string> $snapshot */
        $snapshot = require self::SNAPSHOT;
        self::assertCount(719, $snapshot, 'The frozen pre-migration snapshot must be intact.');

        /** @var array<string, string> $composed */
        $composed = require $root . '/tools/lib/compose-public-surface.php';

        $authorized = $this->authorizedSymbols($root);

        $lost = [];
        $reclassified = [];
        foreach ($snapshot as $fqcn => $disposition) {
            if (isset($authorized[$fqcn])) {
                continue;
            }
            if (!array_key_exists($fqcn, $composed)) {
                $lost[] = $fqcn;
                continue;
            }
            if ($composed[$fqcn] !== $disposition) {
                $reclassified[] = sprintf('%s: %s -> %s', $fqcn, $disposition, $composed[$fqcn]);
            }
        }

        self::assertSame(
            [],
            $lost,
            "Pre-migration disposition(s) no longer declared and not authorized by a public-surface directive:\n"
            . implode("\n", $lost),
        );
        self::assertSame(
            [],
            $reclassified,
            "Pre-migration disposition(s) reclassified without a public-surface deprecation directive:\n"
            . implode("\n", $reclassified),
        );
    }

    /**
     * Every FQCN named by a charter §8.1 directive in the compiled changelog or
     * any fragment, pending or released. Presence here is evidence that a later
     * change went through the governed authorization path; the parity gate
     * enforces that the directive was newly added by the change that needed it.
     *
     * @return array<string, true>
     */
    private function authorizedSymbols(string $root): array
    {
        $sources = [$root . '/CHANGELOG.md'];
        foreach (['changes/unreleased', 'changes/released'] as $directory) {
            $path = $root . '/' . $directory;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'md') {
                    $sources[] = $file->getPathname();
                }
            }
        }

        $authorized = [];
        foreach ($sources as $source) {
            if (!is_file($source)) {
                continue;
            }
            $content = (string) file_get_contents($source);
            if (preg_match_all(self::DIRECTIVE, $content, $matches) > 0) {
                foreach (array_merge($matches[1], $matches[2]) as $fqcn) {
                    if ($fqcn !== '') {
                        $authorized[$fqcn] = true;
                    }
                }
            }
        }

        return $authorized;
    }
}
