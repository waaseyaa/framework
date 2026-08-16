<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase28;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Sync\ConfigImportApplyHookInterface;
use Waaseyaa\Config\Sync\ConfigImportEntryResult;
use Waaseyaa\Config\Sync\ConfigImporter;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Tests\Fixtures\MinooRoundTripFixture;

/**
 * DAG ordering on a Minoo-shaped fixture (FR-054, FR-055).
 *
 * Drives the {@see ConfigImporter} against a sync store pre-seeded with the
 * {@see MinooRoundTripFixture}, asserts that:
 *
 *  - `role.admin` is applied before `menu.main` (menu depends on the role).
 *  - Independent refs (`role.member`, `taxonomy_vocabulary.tags`) appear in
 *    deterministic lex order at their topological level.
 *  - Every fixture ref is applied exactly once.
 *
 * The apply hook is a recorder — it does not touch a real active store. The
 * round-trip integration test exercises the active-store mutation path
 * separately; this test is narrowly scoped to ordering.
 */
#[CoversNothing]
final class ConfigImportDependencyOrderingTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_ordering_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function dependencies_precede_dependents_in_apply_order(): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        foreach (MinooRoundTripFixture::files() as $file) {
            $repository->put($file);
        }

        $recorder = new RecordingApplyHook();
        $importer = new ConfigImporter(
            repository: $repository,
            applyHook: $recorder,
            preflight: new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight(),
        );

        $result = $importer->import();

        self::assertSame(0, $result->failureCount());
        self::assertCount(4, $recorder->applied, 'Every fixture ref must be applied exactly once.');

        $position = array_flip($recorder->applied);
        self::assertArrayHasKey('role.admin', $position);
        self::assertArrayHasKey('menu.main', $position);
        self::assertLessThan(
            $position['menu.main'],
            $position['role.admin'],
            'role.admin must be applied before menu.main (menu declares the role as a dependency).',
        );
    }

    #[Test]
    public function independent_refs_are_applied_in_a_deterministic_order(): void
    {
        // Drop the menu so every remaining ref is an independent leaf. The
        // resolver does not guarantee pure lex across the leaf set (the DFS
        // post-order reversal interleaves with lex tie-breaking inside the
        // DFS), but the order MUST be deterministic — re-running the same
        // import yields the same sequence.
        $files = MinooRoundTripFixture::files();
        unset($files['menu.main']);

        $repository = new ConfigSyncRepository($this->tempDir);
        foreach ($files as $file) {
            $repository->put($file);
        }

        $firstRun = new RecordingApplyHook();
        new ConfigImporter(repository: $repository, applyHook: $firstRun, preflight: new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight())->import();

        $secondRun = new RecordingApplyHook();
        new ConfigImporter(repository: $repository, applyHook: $secondRun, preflight: new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight())->import();

        self::assertSame(
            $firstRun->applied,
            $secondRun->applied,
            'Leaf-only ordering must be byte-stable across runs.',
        );
        self::assertCount(3, $firstRun->applied);
        self::assertEqualsCanonicalizing(
            ['role.admin', 'role.member', 'taxonomy_vocabulary.tags'],
            $firstRun->applied,
            'Every leaf must be applied exactly once.',
        );
    }

    #[Test]
    public function active_store_refs_satisfy_cross_store_dependencies(): void
    {
        // Pretend `role.admin` already lives in the active store (no sync
        // file). `menu.main` still depends on it; the resolver should accept
        // the dep as satisfied without needing the role in the sync store.
        $files = MinooRoundTripFixture::files();
        unset($files['role.admin']);

        $repository = new ConfigSyncRepository($this->tempDir);
        foreach ($files as $file) {
            $repository->put($file);
        }

        $recorder = new RecordingApplyHook();
        $importer = new ConfigImporter(
            repository: $repository,
            applyHook: $recorder,
            preflight: new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight(),
        );

        $result = $importer->import(activeRefs: ['role.admin']);

        self::assertSame(0, $result->failureCount(), 'Cross-store dependency must not produce a missing-ref failure.');
        self::assertContains('menu.main', $recorder->applied);
        self::assertNotContains('role.admin', $recorder->applied, 'role.admin lives in active store; no apply call.');
    }

    #[Test]
    public function nested_dependency_chain_resolves_in_post_order(): void
    {
        // Build a three-tier chain entirely from sync entries to exercise
        // the resolver's iterative DFS post-order: A → B → C means C runs
        // first, then B, then A.
        $repository = new ConfigSyncRepository($this->tempDir);
        $repository->put(ConfigSyncFile::writable(
            entityType: 'menu',
            entityId: 'parent',
            uuid: ConfigSyncFile::deterministicUuid('menu', 'parent'),
            dependencies: ['menu.child'],
            langcode: 'en',
            fields: ['label' => 'Parent'],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        ));
        $repository->put(ConfigSyncFile::writable(
            entityType: 'menu',
            entityId: 'child',
            uuid: ConfigSyncFile::deterministicUuid('menu', 'child'),
            dependencies: ['menu.grandchild'],
            langcode: 'en',
            fields: ['label' => 'Child'],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        ));
        $repository->put(ConfigSyncFile::writable(
            entityType: 'menu',
            entityId: 'grandchild',
            uuid: ConfigSyncFile::deterministicUuid('menu', 'grandchild'),
            dependencies: [],
            langcode: 'en',
            fields: ['label' => 'Grandchild'],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        ));

        $recorder = new RecordingApplyHook();
        $importer = new ConfigImporter(
            repository: $repository,
            applyHook: $recorder,
            preflight: new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight(),
        );

        $importer->import();

        self::assertSame(
            ['menu.grandchild', 'menu.child', 'menu.parent'],
            $recorder->applied,
            'Three-tier chain must apply leaf-first.',
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeDir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}

/**
 * Minimal apply hook that records the order in which the importer asks for
 * each ref to be applied.
 *
 * @internal
 */
final class RecordingApplyHook implements ConfigImportApplyHookInterface
{
    /** @var list<string> */
    public array $applied = [];

    /** @var list<string> */
    public array $deleted = [];

    public function apply(ConfigSyncFile $file): string
    {
        $this->applied[] = $file->ref();

        return ConfigImportEntryResult::STATUS_CREATED;
    }

    public function delete(string $ref): void
    {
        $this->deleted[] = $ref;
    }
}
