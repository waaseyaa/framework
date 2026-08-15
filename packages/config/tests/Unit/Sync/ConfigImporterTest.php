<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\ConfigurationActivationResult;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Activation\ConfigurationRollbackRequest;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Exception\ConfigImportFailedException;
use Waaseyaa\Config\Manifest\VerifiedConfigBundle;
use Waaseyaa\Config\Tests\Fixtures\VerifiedConfigBundleFixture;
use Waaseyaa\Config\Sync\ConfigImportApplyHookInterface;
use Waaseyaa\Config\Sync\ConfigImportEntryResult;
use Waaseyaa\Config\Sync\ConfigImporter;
use Waaseyaa\Config\Sync\ConfigImportPreflightInterface;
use Waaseyaa\Config\Sync\ConfigImportPreflightException;
use Waaseyaa\Config\Sync\ConfigImportResult;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Sync\RefusingConfigImportPreflight;

#[CoversClass(ConfigImporter::class)]
#[CoversClass(ConfigImportResult::class)]
#[CoversClass(ConfigImportEntryResult::class)]
final class ConfigImporterTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_importer_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function fresh_import_applies_files_in_topological_order(): void
    {
        $repository = $this->seed([
            'role.admin' => [],
            'menu.main' => ['role.admin'],
            'taxonomy_vocabulary.tags' => [],
        ]);

        $applied = [];
        $hook = $this->makeHook(applyOrder: $applied);
        $importer = new ConfigImporter($repository, $hook, new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight());

        $result = $importer->import();

        self::assertSame(0, $result->failureCount());
        // Dependencies-first: role.admin precedes menu.main; taxonomy_vocabulary.tags is
        // independent so its position is determined by lex order at its topological level.
        $roleIndex = array_search('role.admin', $applied, true);
        $menuIndex = array_search('menu.main', $applied, true);
        self::assertNotFalse($roleIndex);
        self::assertNotFalse($menuIndex);
        self::assertLessThan($menuIndex, $roleIndex, 'menu.main must be applied after role.admin.');
        self::assertContains('taxonomy_vocabulary.tags', $applied);
    }

    #[Test]
    public function dry_run_does_not_call_apply_hook(): void
    {
        $repository = $this->seed(['role.admin' => []]);
        $applied = [];
        $hook = $this->makeHook(applyOrder: $applied);

        $importer = new ConfigImporter($repository, $hook, new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight());
        $result = $importer->import(dryRun: true);

        self::assertTrue($result->dryRun);
        self::assertSame([], $applied);
        self::assertCount(1, $result->entries);
        self::assertSame(ConfigImportEntryResult::STATUS_UPDATED, $result->entries[0]->status);
    }

    #[Test]
    public function mandatoryPreflightRunsBeforeApplyEvenForDryRun(): void
    {
        $repository = $this->seed(['role.admin' => []]);
        $applied = [];
        $hook = $this->makeHook(applyOrder: $applied);
        $importer = new ConfigImporter($repository, $hook, new RefusingConfigImportPreflight());

        try {
            $importer->import(dryRun: true, deleteOrphans: true, activeRefs: ['role.legacy']);
            self::fail('Import bypassed its mandatory preflight.');
        } catch (ConfigImportPreflightException $exception) {
            self::assertStringContainsString('CFG-03 schema, manifest, and drift gates', $exception->getMessage());
        }
        self::assertSame([], $applied);
    }

    #[Test]
    public function per_entity_failure_is_recorded_and_run_continues(): void
    {
        $repository = $this->seed(['role.admin' => [], 'role.member' => []]);

        $hook = new class implements ConfigImportApplyHookInterface {
            public function apply(ConfigSyncFile $file): string
            {
                if ($file->ref() === 'role.admin') {
                    throw ConfigImportFailedException::applyFailed('role.admin', 'db lock timeout');
                }

                return ConfigImportEntryResult::STATUS_CREATED;
            }

            public function delete(string $ref): void {}
        };

        $importer = new ConfigImporter($repository, $hook, new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight());
        $result = $importer->import();

        self::assertSame(1, $result->failureCount());
        $statuses = array_map(static fn($e) => $e->status, $result->entries);
        self::assertContains(ConfigImportEntryResult::STATUS_FAILED, $statuses);
        self::assertContains(ConfigImportEntryResult::STATUS_CREATED, $statuses);
    }

    #[Test]
    public function halt_on_error_stops_after_first_failure(): void
    {
        $repository = $this->seed([
            'role.admin' => [],
            'role.member' => [],
            'role.viewer' => [],
        ]);

        $hook = new class implements ConfigImportApplyHookInterface {
            /** @var list<string> */
            public array $calls = [];

            public function apply(ConfigSyncFile $file): string
            {
                $this->calls[] = $file->ref();
                throw ConfigImportFailedException::applyFailed($file->ref(), 'boom');
            }

            public function delete(string $ref): void {}
        };

        $importer = new ConfigImporter($repository, $hook, new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight());
        $result = $importer->import(haltOnError: true);

        self::assertCount(1, $hook->calls, '--halt-on-error must stop after the first failure.');
        self::assertSame(1, $result->failureCount());
    }

    #[Test]
    public function no_dependency_check_bypasses_resolver_and_logs_warning(): void
    {
        $repository = $this->seed([
            // Circular declarations — would crash the resolver.
            'menu.main' => ['role.admin'],
            'role.admin' => ['menu.main'],
        ]);

        $applied = [];
        $hook = $this->makeHook(applyOrder: $applied);

        /** @var list<array{string, string, array<string, mixed>}> $auditLog */
        $auditLog = [];
        $auditor = static function (string $level, string $message, array $context) use (&$auditLog): void {
            $auditLog[] = [$level, $message, $context];
        };

        $importer = new ConfigImporter(
            $repository,
            $hook,
            $this->verifiedPreflight($repository),
            auditLogger: $auditor,
        );

        $result = $importer->import(noDependencyCheck: true);

        self::assertSame(0, $result->failureCount());
        self::assertCount(2, $applied);
        $warnings = array_filter($auditLog, static fn($e) => $e[0] === 'warning');
        self::assertCount(1, $warnings, 'Bypass must emit exactly one audit warning.');
    }

    #[Test]
    public function dependency_cycle_aborts_apply_loop_with_failed_entry(): void
    {
        $repository = $this->seed([
            'menu.main' => ['role.admin'],
            'role.admin' => ['menu.main'],
        ]);

        $applied = [];
        $hook = $this->makeHook(applyOrder: $applied);

        $importer = new ConfigImporter($repository, $hook, new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight());
        $result = $importer->import();

        self::assertSame(1, $result->failureCount());
        self::assertSame([], $applied, 'Cycle must prevent any apply calls.');
    }

    #[Test]
    public function orphan_warn_default_emits_unchanged_entry_and_audit_info(): void
    {
        $repository = $this->seed(['role.admin' => []]);
        $applied = [];
        $hook = $this->makeHook(applyOrder: $applied);

        $auditLog = [];
        $auditor = static function (string $level, string $message, array $context) use (&$auditLog): void {
            $auditLog[] = [$level, $message, $context];
        };

        $importer = new ConfigImporter($repository, $hook, new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight(), auditLogger: $auditor);

        // role.legacy exists in active store but has no sync file.
        $result = $importer->import(activeRefs: ['role.admin', 'role.legacy']);

        $orphanEntry = array_values(array_filter(
            $result->entries,
            static fn($e) => $e->ref === 'role.legacy',
        ))[0] ?? null;

        self::assertNotNull($orphanEntry);
        self::assertSame(ConfigImportEntryResult::STATUS_UNCHANGED, $orphanEntry->status);
        $infos = array_filter($auditLog, static fn($e) => $e[0] === 'info');
        self::assertNotEmpty($infos, 'Orphan-warn must surface an audit info entry.');
    }

    #[Test]
    public function delete_orphans_invokes_hook_delete_and_records_deleted_status(): void
    {
        $repository = $this->seed(['role.admin' => []]);

        $hook = new class implements ConfigImportApplyHookInterface {
            /** @var list<string> */
            public array $deleted = [];

            public function apply(ConfigSyncFile $file): string
            {
                return ConfigImportEntryResult::STATUS_UPDATED;
            }

            public function delete(string $ref): void
            {
                $this->deleted[] = $ref;
            }
        };

        $importer = new ConfigImporter($repository, $hook, new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight());
        $result = $importer->import(
            deleteOrphans: true,
            activeRefs: ['role.admin', 'role.legacy'],
        );

        self::assertSame(['role.legacy'], $hook->deleted);
        $orphanEntry = array_values(array_filter(
            $result->entries,
            static fn($e) => $e->ref === 'role.legacy',
        ))[0] ?? null;
        self::assertNotNull($orphanEntry);
        self::assertSame(ConfigImportEntryResult::STATUS_DELETED, $orphanEntry->status);
    }

    #[Test]
    public function delete_orphans_dry_run_does_not_call_hook(): void
    {
        $repository = $this->seed(['role.admin' => []]);

        $hook = new class implements ConfigImportApplyHookInterface {
            /** @var list<string> */
            public array $deleted = [];

            public function apply(ConfigSyncFile $file): string
            {
                return ConfigImportEntryResult::STATUS_UPDATED;
            }

            public function delete(string $ref): void
            {
                $this->deleted[] = $ref;
            }
        };

        $importer = new ConfigImporter($repository, $hook, new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight());
        $result = $importer->import(
            dryRun: true,
            deleteOrphans: true,
            activeRefs: ['role.admin', 'role.legacy'],
        );

        self::assertSame([], $hook->deleted);
        $orphanEntry = array_values(array_filter(
            $result->entries,
            static fn($e) => $e->ref === 'role.legacy',
        ))[0] ?? null;
        self::assertNotNull($orphanEntry);
        self::assertSame(ConfigImportEntryResult::STATUS_DELETED, $orphanEntry->status);
    }

    #[Test]
    public function summary_line_matches_canonical_format(): void
    {
        $entries = [
            new ConfigImportEntryResult(ref: 'role.admin', status: ConfigImportEntryResult::STATUS_CREATED),
            new ConfigImportEntryResult(ref: 'role.member', status: ConfigImportEntryResult::STATUS_UPDATED),
            new ConfigImportEntryResult(ref: 'role.viewer', status: ConfigImportEntryResult::STATUS_UNCHANGED),
            new ConfigImportEntryResult(ref: 'role.bad', status: ConfigImportEntryResult::STATUS_FAILED, reason: 'x'),
            new ConfigImportEntryResult(ref: 'role.legacy', status: ConfigImportEntryResult::STATUS_DELETED),
        ];
        $result = new ConfigImportResult(entries: $entries);

        self::assertSame(
            '1 created, 1 updated, 1 deleted, 1 failed, 1 unchanged.',
            $result->summary(),
        );
    }

    #[Test]
    public function transactionalImportBuildsOneCompleteReplacementAndNeverCallsLegacyHooks(): void
    {
        $repository = $this->seed(['system.site' => []]);
        $activeSite = $this->file('system.site', [], ['name' => 'Old']);
        $activeRole = $this->file('role.legacy', [], ['label' => 'Legacy']);
        $expected = new ConfigurationActiveToken(str_repeat('a', 64), 4);
        $committed = new ConfigurationActiveToken(str_repeat('b', 64), 5);
        $activator = new class ($committed) implements ConfigurationActivatorInterface {
            public ?ConfigurationActivationRequest $request = null;
            public function __construct(private readonly ConfigurationActiveToken $committed) {}
            public function activate(ConfigurationActivationRequest $request): ConfigurationActivationResult
            {
                $this->request = $request;
                return new ConfigurationActivationResult('committed', $this->committed, $request->requestId, str_repeat('c', 64));
            }
            public function rollback(ConfigurationRollbackRequest $request): ConfigurationActivationResult
            {
                throw new \LogicException('not used');
            }
            public function committedResult(string $requestId): ?ConfigurationActivationResult
            {
                return null;
            }
            public function currentToken(): ?ConfigurationActiveToken
            {
                return null;
            }
            public function readGeneration(ConfigurationActiveToken $token): iterable
            {
                return [];
            }
        };
        $hook = new class implements ConfigImportApplyHookInterface {
            public function apply(ConfigSyncFile $file): string
            {
                throw new \LogicException('legacy apply called');
            }
            public function delete(string $ref): void
            {
                throw new \LogicException('legacy delete called');
            }
        };
        $audit = [];
        $importer = new ConfigImporter(
            $repository,
            $hook,
            $this->verifiedPreflight($repository),
            auditLogger: static function (string $level, string $message, array $context) use (&$audit): void {
                $audit[] = [$level, $message, $context];
            },
            activator: $activator,
        );

        $result = $importer->import(
            deleteOrphans: true,
            activeRefs: [$activeSite->ref(), $activeRole->ref()],
            activationRequestId: 'transactional-import-1',
            expectedToken: $expected,
            activeFiles: [$activeSite, $activeRole],
        );

        self::assertSame(0, $result->failureCount());
        self::assertNotNull($activator->request);
        self::assertTrue($activator->request->completeReplacement);
        self::assertEquals($expected, $activator->request->expectedToken);
        self::assertSame(['role.legacy' => $activeRole->contentHash()], $activator->request->tombstones());
        self::assertSame(['updated', 'deleted'], array_column($result->entries, 'status'));
        self::assertCount(1, $audit);
        self::assertSame($committed->generationId, $audit[0][2]['generation_id']);
    }

    #[Test]
    public function transactionalImportWithoutRequestIdentityFailsBeforeActivation(): void
    {
        $repository = $this->seed(['system.site' => []]);
        $activator = $this->createMock(ConfigurationActivatorInterface::class);
        $activator->expects($this->never())->method('activate');
        $unused = [];
        $importer = new ConfigImporter(
            $repository,
            $this->makeHook($unused),
            $this->verifiedPreflight($repository),
            activator: $activator,
        );

        $result = $importer->import();

        self::assertSame(1, $result->failureCount());
        self::assertStringContainsString('activation-request-id', (string) $result->entries[0]->reason);
    }

    #[Test]
    public function transactionalDryRunBuildsTheExactPlanWithoutActivationOrLegacyMutation(): void
    {
        $repository = $this->seed(['system.site' => []]);
        $activeSite = $this->file('system.site', [], ['name' => 'Old']);
        $expected = new ConfigurationActiveToken(str_repeat('a', 64), 4);
        $activator = $this->createMock(ConfigurationActivatorInterface::class);
        $activator->expects($this->never())->method('activate');
        $hook = new class implements ConfigImportApplyHookInterface {
            public function apply(ConfigSyncFile $file): string
            {
                throw new \LogicException('legacy apply called');
            }
            public function delete(string $ref): void
            {
                throw new \LogicException('legacy delete called');
            }
        };
        $importer = new ConfigImporter(
            $repository,
            $hook,
            $this->verifiedPreflight($repository),
            activator: $activator,
        );

        $result = $importer->import(
            dryRun: true,
            activeRefs: [$activeSite->ref()],
            expectedToken: $expected,
            activeFiles: [$activeSite],
        );

        self::assertTrue($result->dryRun);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $result->generationId);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $result->planHash);
        self::assertSame(ConfigImportEntryResult::STATUS_UPDATED, $result->entries[0]->status);
    }

    /**
     * @param array<string, list<string>> $refsWithDeps Map of ref => declared deps.
     */
    private function seed(array $refsWithDeps): ConfigSyncRepository
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        foreach ($refsWithDeps as $ref => $dependencies) {
            $file = $this->file($ref, $dependencies);
            $repository->put($file);
        }

        return $repository;
    }

    private function verifiedPreflight(ConfigSyncRepository $repository): ConfigImportPreflightInterface
    {
        $bundle = VerifiedConfigBundleFixture::fromFiles(array_values(iterator_to_array($repository->list())));

        return new class ($bundle) implements ConfigImportPreflightInterface {
            public function __construct(private readonly VerifiedConfigBundle $bundle) {}

            public function assertReady(
                array $syncFiles,
                array $activeRefs,
                bool $dryRun,
                bool $deleteOrphans,
                bool $noDependencyCheck,
            ): ?VerifiedConfigBundle {
                return $this->bundle;
            }
        };
    }

    /** @param list<string> $dependencies @param array<string, mixed> $fields */
    private function file(string $ref, array $dependencies = [], array $fields = []): ConfigSyncFile
    {
        [$entityType, $entityId] = explode('.', $ref, 2);

        return ConfigSyncFile::writable(
            entityType: $entityType,
            entityId: $entityId,
            uuid: ConfigSyncFile::deterministicUuid($entityType, $entityId),
            dependencies: $dependencies,
            langcode: 'en',
            fields: $fields,
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
    }

    /**
     * @param list<string> $applyOrder Captured ref-order of `apply()` calls.
     */
    private function makeHook(array &$applyOrder): ConfigImportApplyHookInterface
    {
        return new class ($applyOrder) implements ConfigImportApplyHookInterface {
            /** @param list<string> $applyOrder */
            public function __construct(private array &$applyOrder) {}

            public function apply(ConfigSyncFile $file): string
            {
                $this->applyOrder[] = $file->ref();

                return ConfigImportEntryResult::STATUS_CREATED;
            }

            public function delete(string $ref): void {}
        };
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
