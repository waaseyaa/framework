<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FW-PACKAGE-CONVERGENCE-01 (#3118): docs/audits/packages/coverage-index.json
 * has exactly one row per package directory plus the root aggregate, with
 * each row's identity taken from its real manifest and its states drawn from
 * the program vocabulary. A package added, renamed or removed must update the
 * index in the same change.
 */
#[CoversNothing]
final class PackageConvergenceCoverageIndexTest extends TestCase
{
    private const string INDEX = 'docs/audits/packages/coverage-index.json';
    private const array AUDIT_STATES = ['not assessed', 'inventory only', 'in progress', 'assessed', 'needs delta review'];
    private const array REMEDIATION_STATES = ['not triaged', 'no action required', 'planned', 'in progress', 'resolved', 'accepted residual'];

    /** @return array<string, mixed> */
    private function index(): array
    {
        $root = dirname(__DIR__, 2);

        return json_decode((string) file_get_contents($root . '/' . self::INDEX), true, 32, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function every_package_directory_and_the_root_aggregate_has_exactly_one_row_matching_its_manifest(): void
    {
        $root = dirname(__DIR__, 2);
        $expected = [];
        $rootManifest = json_decode((string) file_get_contents($root . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
        $expected['.'] = [$rootManifest['name'], 'root-aggregate'];
        foreach (glob($root . '/packages/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $path = 'packages/' . basename($directory);
            if (is_file($directory . '/composer.json')) {
                $manifest = json_decode((string) file_get_contents($directory . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
                $expected[$path] = [$manifest['name'], ($manifest['type'] ?? 'library') === 'metapackage' ? 'metapackage' : 'library'];
            } else {
                $manifest = json_decode((string) file_get_contents($directory . '/package.json'), true, 32, JSON_THROW_ON_ERROR);
                $expected[$path] = [$manifest['name'], 'npm'];
            }
        }
        ksort($expected);

        $actual = [];
        foreach ($this->index()['packages'] as $row) {
            self::assertArrayNotHasKey($row['path'], $actual, 'duplicate row for ' . $row['path']);
            $actual[$row['path']] = [$row['package'], $row['form']];
        }
        ksort($actual);

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function every_row_uses_program_states_and_backs_progress_with_evidence(): void
    {
        $index = $this->index();
        self::assertSame('FW-PACKAGE-CONVERGENCE-01', $index['record']);
        self::assertSame(self::AUDIT_STATES, $index['audit_states']);
        self::assertSame(self::REMEDIATION_STATES, $index['remediation_states']);

        $packages = array_column($index['packages'], 'package');
        $sorted = $packages;
        sort($sorted);
        self::assertSame($sorted, $packages, 'rows are sorted by package name');

        foreach ($index['packages'] as $row) {
            $label = $row['package'];
            self::assertSame(['package', 'path', 'form', 'audit_state', 'remediation_state', 'audit_base', 'owner_issue', 'evidence', 'notes'], array_keys($row), $label);
            self::assertContains($row['audit_state'], self::AUDIT_STATES, $label);
            self::assertContains($row['remediation_state'], self::REMEDIATION_STATES, $label);
            self::assertIsArray($row['evidence'], $label);
            if ($row['audit_state'] === 'not assessed') {
                self::assertNull($row['audit_base'], $label . ': an unassessed package has no audit base');
                continue;
            }
            self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $row['audit_base'], $label . ': audit base is a full commit id');
            self::assertMatchesRegularExpression('/^#\d+$/', (string) $row['owner_issue'], $label . ': audited packages name an owner issue');
            self::assertNotSame([], $row['evidence'], $label . ': audited packages link evidence');
        }
    }
}
