<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * §11 acceptance proof for #2901 (FW-DELIVERY-SURFACE-01): "every disposition
 * preserved; no broadening."
 *
 * `5bac44286` is the exact parent commit this migration was cut from (the
 * worktree's starting HEAD) — the LAST commit whose docs/public-surface-map.php
 * was hand-authored, before any packages/<pkg>/public-surface.php existed. It
 * is a fixed historical pin, not a moving target: this test asserts the
 * composed declaration plane at the time of migration reproduced that exact
 * aggregate, key-for-key and value-for-value, with no entry added, removed, or
 * reclassified in the same change that introduced the declaration plane
 * itself. Once this PR merges, `5bac44286` remains historical evidence — the
 * assertion stays meaningful precisely because it is pinned, not because it
 * would still hold if compared against a much later HEAD (ordinary,
 * authorized surface changes since would legitimately diverge from a stale
 * pin). Verifying *current* dispositions is `SurfaceDeclarationValidationTest`
 * and the parity gate's job, not this test's.
 */
#[CoversNothing]
final class SurfaceMigrationFidelityTest extends TestCase
{
    private const string PRE_MIGRATION_PIN = '5bac44286';

    #[Test]
    public function the_composed_declaration_plane_reproduces_the_pre_migration_aggregate_exactly(): void
    {
        $root = dirname(__DIR__, 2);

        require_once $root . '/tools/lib/SurfaceDeclarations.php';
        $declarations = \Waaseyaa\Tooling\SurfaceDeclarations::load($root);
        $composed = $declarations->compose();

        $baseline = $this->loadBaselineMap($root);

        self::assertGreaterThanOrEqual(704, count($baseline), 'Sanity check: the pinned baseline itself must be non-trivial.');
        self::assertSame(
            count($baseline),
            count($composed),
            sprintf(
                'Composed declaration count (%d) must equal the pre-migration aggregate at %s (%d) — no entry may be added or dropped by the migration itself.',
                count($composed),
                self::PRE_MIGRATION_PIN,
                count($baseline),
            ),
        );

        $missingFromComposed = array_diff_key($baseline, $composed);
        self::assertSame(
            [],
            $missingFromComposed,
            "Entrie(s) present in the pre-migration aggregate but missing from the composed declarations (dropped by migration):\n"
            . implode("\n", array_keys($missingFromComposed)),
        );

        $addedByComposed = array_diff_key($composed, $baseline);
        self::assertSame(
            [],
            $addedByComposed,
            "Entrie(s) present in the composed declarations but absent from the pre-migration aggregate (broadened by migration):\n"
            . implode("\n", array_keys($addedByComposed)),
        );

        $reclassified = [];
        foreach ($baseline as $fqcn => $disposition) {
            if (($composed[$fqcn] ?? null) !== $disposition) {
                $reclassified[$fqcn] = ['was' => $disposition, 'now' => $composed[$fqcn] ?? '(missing)'];
            }
        }
        self::assertSame(
            [],
            $reclassified,
            "Entrie(s) whose disposition changed between the pre-migration aggregate and the composed declarations:\n"
            . print_r($reclassified, true),
        );
    }

    /** @return array<string, string> */
    private function loadBaselineMap(string $root): array
    {
        $process = new \Symfony\Component\Process\Process(
            ['bash', $root . '/bin/git', '-C', $root, 'show', self::PRE_MIGRATION_PIN . ':docs/public-surface-map.php'],
        );
        $process->run();
        self::assertTrue($process->isSuccessful(), 'Could not read the pinned pre-migration aggregate: ' . $process->getErrorOutput());

        $temporary = tempnam(sys_get_temp_dir(), 'waaseyaa-surface-baseline-');
        self::assertNotFalse($temporary);
        file_put_contents($temporary, $process->getOutput());
        /** @var mixed $map */
        $map = require $temporary;
        @unlink($temporary);
        self::assertIsArray($map);

        return $map;
    }
}
