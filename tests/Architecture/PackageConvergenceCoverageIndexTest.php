<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/bin/lib/package-coverage.php';

/**
 * FW-PACKAGE-CONVERGENCE-01 (#3118): docs/audits/packages/coverage-index.json
 * has exactly one row per package directory plus the root aggregate, with
 * each row's identity taken from its real manifest and its states drawn from
 * the program vocabulary. Rows beyond "not assessed" record their base, date
 * and dependency identity and cite only files committed in this repository.
 *
 * Everything here works in a shallow checkout. Checks that need history (the
 * dependency identity against composer.lock at the base, and captured copies
 * against their source commits) live in bin/check-package-coverage-history,
 * which ci/verify-gates runs with full history and which fails closed.
 */
#[CoversNothing]
final class PackageConvergenceCoverageIndexTest extends TestCase
{
    private const array AUDIT_STATES = ['not assessed', 'inventory only', 'in progress', 'assessed', 'needs delta review'];
    private const array REMEDIATION_STATES = ['not triaged', 'no action required', 'planned', 'in progress', 'resolved', 'accepted residual'];
    private const string SAFE_PATH = '#^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*$#';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function every_package_directory_and_the_root_aggregate_has_exactly_one_row_matching_its_manifest(): void
    {
        $expected = [];
        $rootManifest = json_decode((string) file_get_contents($this->root . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
        $expected['.'] = [$rootManifest['name'], 'root-aggregate'];
        foreach (glob($this->root . '/packages/*', GLOB_ONLYDIR) ?: [] as $directory) {
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
        foreach (\packageCoverageIndex($this->root)['packages'] as $row) {
            self::assertArrayNotHasKey($row['path'], $actual, 'duplicate row for ' . $row['path']);
            $actual[$row['path']] = [$row['package'], $row['form']];
        }
        ksort($actual);

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function every_row_uses_program_states_and_records_audit_identity(): void
    {
        $index = \packageCoverageIndex($this->root);
        self::assertSame(3, $index['schema_version']);
        self::assertSame('FW-PACKAGE-CONVERGENCE-01', $index['record']);
        self::assertSame(self::AUDIT_STATES, $index['audit_states']);
        self::assertSame(self::REMEDIATION_STATES, $index['remediation_states']);
        self::assertSame(['repository-path'], array_keys($index['evidence_kinds']));

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
            if ($row['audit_state'] === 'assessed') {
                $record = 'docs/audits/packages/' . substr($row['package'], strlen('waaseyaa/')) . '.md';
                self::assertContains(['kind' => 'repository-path', 'path' => $record], $row['evidence'], $label . ': an assessed package links its audit record');
            }
            foreach ($row['evidence'] as $evidence) {
                self::assertIsArray($evidence, $label . ': evidence entries are typed references, not free text');
                self::assertSame(['kind', 'path'], array_keys($evidence), $label);
                self::assertSame('repository-path', $evidence['kind'], $label . ': evidence is a committed repository file');
                self::assertMatchesRegularExpression(self::SAFE_PATH, $evidence['path'], $label);
                self::assertFileExists($this->root . '/' . $evidence['path'], $label . ': evidence file is committed');
            }
        }
    }

    #[Test]
    public function every_captured_evidence_file_is_cited_and_matches_its_pinned_digest(): void
    {
        $cited = [];
        foreach (\packageCoverageIndex($this->root)['packages'] as $row) {
            foreach ($row['evidence'] as $evidence) {
                $cited[] = $evidence['path'];
            }
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/' . PACKAGE_COVERAGE_EVIDENCE, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            $files[] = PACKAGE_COVERAGE_EVIDENCE . str_replace('\\', '/', substr($entry->getPathname(), strlen($this->root . '/' . PACKAGE_COVERAGE_EVIDENCE)));
        }
        sort($files);
        self::assertNotSame([], $files);

        foreach ($files as $file) {
            self::assertContains($file, $cited, $file . ' is captured but no index row cites it');
            $captured = \packageCoverageEvidence((string) file_get_contents($this->root . '/' . $file));
            self::assertSame(hash('sha256', $captured['body']), $captured['sha256'], $file);
        }
    }

    #[Test]
    public function the_evidence_parser_rejects_a_missing_header_or_an_altered_body(): void
    {
        $body = "captured\r\nbytes\n";
        $valid = "source: issue waaseyaa/framework#1\ncaptured: 2026-09-23\nbody-sha256: " . hash('sha256', $body) . "\n---\n" . $body;
        self::assertSame($body, \packageCoverageEvidence($valid)['body']);
        self::assertSame('issue', \packageCoverageEvidence($valid)['source_kind']);

        foreach ([
            'altered body' => $valid . 'x',
            'missing header' => $body,
            'free-text source' => str_replace('source: issue waaseyaa/framework#1', 'source: see the issue', $valid),
            'short commit' => str_replace('source: issue waaseyaa/framework#1', 'source: commit abc123 docs/a.md', $valid),
        ] as $label => $bytes) {
            try {
                \packageCoverageEvidence($bytes);
                self::fail($label . ' was accepted');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
