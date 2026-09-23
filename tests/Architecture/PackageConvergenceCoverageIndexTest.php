<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/bin/lib/repository-files.php';

/**
 * FW-PACKAGE-CONVERGENCE-01 (#3118): docs/audits/packages/coverage-index.json
 * has exactly one row per package directory plus the root aggregate, with
 * each row's identity taken from its real manifest and its states drawn from
 * the program vocabulary. A package added, renamed or removed must update the
 * index in the same change. Rows beyond "not assessed" must record their
 * base, date and dependency identity, and cite only immutable evidence.
 */
#[CoversNothing]
final class PackageConvergenceCoverageIndexTest extends TestCase
{
    private const string INDEX = 'docs/audits/packages/coverage-index.json';
    private const array AUDIT_STATES = ['not assessed', 'inventory only', 'in progress', 'assessed', 'needs delta review'];
    private const array REMEDIATION_STATES = ['not triaged', 'no action required', 'planned', 'in progress', 'resolved', 'accepted residual'];
    private const array EVIDENCE_KINDS = ['repository-path', 'commit-path', 'pull-request', 'issue-snapshot'];
    private const string SAFE_PATH = '#^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*$#';

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
    public function every_row_uses_program_states_and_records_audit_identity(): void
    {
        $index = $this->index();
        self::assertSame(2, $index['schema_version']);
        self::assertSame('FW-PACKAGE-CONVERGENCE-01', $index['record']);
        self::assertSame(self::AUDIT_STATES, $index['audit_states']);
        self::assertSame(self::REMEDIATION_STATES, $index['remediation_states']);
        self::assertSame(self::EVIDENCE_KINDS, array_keys($index['evidence_kinds']));

        $packages = array_column($index['packages'], 'package');
        $sorted = $packages;
        sort($sorted);
        self::assertSame($sorted, $packages, 'rows are sorted by package name');

        foreach ($index['packages'] as $row) {
            $label = $row['package'];
            self::assertSame(['package', 'path', 'form', 'audit_state', 'remediation_state', 'audit_base', 'audit_date', 'dependency_identity', 'owner_issue', 'evidence', 'notes'], array_keys($row), $label);
            self::assertContains($row['audit_state'], self::AUDIT_STATES, $label);
            self::assertContains($row['remediation_state'], self::REMEDIATION_STATES, $label);
            self::assertIsArray($row['evidence'], $label);
            if ($row['audit_state'] === 'not assessed') {
                self::assertNull($row['audit_base'], $label . ': an unassessed package has no audit base');
                self::assertNull($row['audit_date'], $label . ': an unassessed package has no audit date');
                self::assertNull($row['dependency_identity'], $label . ': an unassessed package has no dependency identity');
                self::assertSame([], $row['evidence'], $label . ': an unassessed package has no evidence');
                continue;
            }
            self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $row['audit_base'], $label . ': audit base is a full commit id');
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $row['audit_date'], $label . ': audit date');
            self::assertMatchesRegularExpression('/^composer\.lock sha256:[0-9a-f]{64}$/', (string) $row['dependency_identity'], $label . ': dependency identity');
            self::assertMatchesRegularExpression('/^#\d+$/', (string) $row['owner_issue'], $label . ': audited packages name an owner issue');
            self::assertNotSame([], $row['evidence'], $label . ': audited packages link evidence');
            $lock = $this->gitShow($row['audit_base'], 'composer.lock');
            if ($lock !== null) {
                self::assertSame('composer.lock sha256:' . hash('sha256', $lock), $row['dependency_identity'], $label . ': dependency identity matches the lock at the audit base');
            }
            if ($row['audit_state'] === 'assessed') {
                $record = 'docs/audits/packages/' . substr($row['package'], strlen('waaseyaa/')) . '.md';
                self::assertContains(['kind' => 'repository-path', 'path' => $record], $row['evidence'], $label . ': an assessed package links its audit record');
            }
            foreach ($row['evidence'] as $evidence) {
                self::assertIsArray($evidence, $label . ': evidence entries are typed references, not free text');
                $this->assertImmutableEvidence($label, $evidence);
            }
        }
    }

    /** @param array<string, mixed> $evidence */
    private function assertImmutableEvidence(string $label, array $evidence): void
    {
        $root = dirname(__DIR__, 2);
        $kind = $evidence['kind'] ?? null;
        self::assertContains($kind, self::EVIDENCE_KINDS, $label . ': evidence kind');
        match ($kind) {
            'repository-path' => (function () use ($root, $label, $evidence): void {
                self::assertSame(['kind', 'path'], array_keys($evidence), $label);
                self::assertMatchesRegularExpression(self::SAFE_PATH, $evidence['path'], $label);
                self::assertFileExists($root . '/' . $evidence['path'], $label . ': repository path is tracked here');
            })(),
            'commit-path' => (function () use ($label, $evidence): void {
                self::assertSame(['kind', 'commit', 'path'], array_keys($evidence), $label);
                self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $evidence['commit'], $label . ': full commit id');
                self::assertMatchesRegularExpression(self::SAFE_PATH, $evidence['path'], $label);
                if ($this->gitShow($evidence['commit'], $evidence['path']) === null && $this->commitAvailable($evidence['commit'])) {
                    self::fail(sprintf('%s: %s does not exist at %s', $label, $evidence['path'], $evidence['commit']));
                }
            })(),
            'pull-request' => (function () use ($label, $evidence): void {
                self::assertSame(['kind', 'number', 'merge_commit'], array_keys($evidence), $label);
                self::assertIsInt($evidence['number'], $label);
                self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $evidence['merge_commit'], $label . ': pinned merge commit');
            })(),
            default => (function () use ($label, $evidence): void {
                self::assertSame(['kind', 'number', 'captured', 'body_sha256'], array_keys($evidence), $label);
                self::assertIsInt($evidence['number'], $label);
                self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $evidence['captured'], $label);
                self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $evidence['body_sha256'], $label . ': pinned body digest');
            })(),
        };
    }

    /**
     * File bytes at a commit, or null when the commit or path isn't available
     * in this clone (a shallow CI checkout may lack older commits).
     */
    private function gitShow(string $commit, string $path): ?string
    {
        [$exitCode, $stdout] = \repositoryGit(dirname(__DIR__, 2), ['show', $commit . ':' . $path]);

        return $exitCode === 0 ? $stdout : null;
    }

    private function commitAvailable(string $commit): bool
    {
        [$exitCode] = \repositoryGit(dirname(__DIR__, 2), ['cat-file', '-e', $commit . '^{commit}']);

        return $exitCode === 0;
    }
}
