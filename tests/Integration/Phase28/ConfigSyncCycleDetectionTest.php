<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase28;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Config\Dependency\DependencyResolver;
use Waaseyaa\Config\Dependency\Exception\ConfigDependencyCycleException;
use Waaseyaa\Config\Sync\ConfigImportApplyHookInterface;
use Waaseyaa\Config\Sync\ConfigImportEntryResult;
use Waaseyaa\Config\Sync\ConfigImporter;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Tests\Fixtures\CycleFixture;

/**
 * Cycle-detection integration (FR-056).
 *
 * Verifies the full operator-visible contract when a sync store contains a
 * deliberate dependency cycle ({@see CycleFixture}):
 *
 *  - The {@see DependencyResolver} raises {@see ConfigDependencyCycleException}
 *    carrying the closed cycle path (first == last, length >= 3).
 *  - The exception's stable error code is `'config.dependency.cycle'`.
 *  - The {@see ConfigImporter} converts the resolver failure into exactly one
 *    `STATUS_FAILED` entry and applies nothing (FR-028 fail-fast).
 *  - The `--no-dependency-check` bypass lets the importer plough through the
 *    cycle and apply every file in declaration order, surfacing exactly one
 *    audit warning (`config.audit`, `'warning'`).
 */
#[CoversNothing]
final class ConfigSyncCycleDetectionTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_cycle_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tempDir);
    }

    #[Test]
    public function dependency_resolver_surfaces_full_cycle_path(): void
    {
        $resolver = new DependencyResolver();

        $caught = null;
        try {
            $resolver->resolve(CycleFixture::declarations());
        } catch (ConfigDependencyCycleException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'Cycle fixture must raise ConfigDependencyCycleException.');
        $cycle = $caught->getCycle();
        self::assertGreaterThanOrEqual(3, \count($cycle), 'Cycle path must include at least [A, B, A].');
        self::assertSame($cycle[0], $cycle[\count($cycle) - 1], 'Cycle path must close on itself.');
        // Both fixture members must appear in the path.
        self::assertContains('role.admin', $cycle);
        self::assertContains('role.member', $cycle);
        // Stable error code on the framework-public surface.
        self::assertSame('config.dependency.cycle', $caught->errorCode);
        // String accessor alias mirrors the readonly errorCode.
        self::assertSame('config.dependency.cycle', $caught->code);
        // Inherited \Exception::getCode() remains an integer 0 (see exception
        // docblock — `code` is virtual; `getCode()` returns the parent int).
        self::assertSame(0, $caught->getCode());
    }

    #[Test]
    public function importer_records_single_failed_entry_for_cycle_and_applies_nothing(): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        foreach (CycleFixture::files() as $file) {
            $repository->put($file);
        }

        $applied = [];
        $hook = new class ($applied) implements ConfigImportApplyHookInterface {
            /** @param list<string> $applied */
            public function __construct(private array &$applied) {}

            public function apply(ConfigSyncFile $file): string
            {
                $this->applied[] = $file->ref();

                return ConfigImportEntryResult::STATUS_CREATED;
            }

            public function delete(string $ref): void {}
        };

        $importer = new ConfigImporter(
            repository: $repository,
            applyHook: $hook,
            preflight: new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight(),
        );

        $result = $importer->import();

        self::assertSame(1, $result->failureCount(), 'Cycle must produce exactly one STATUS_FAILED entry.');
        self::assertSame([], $applied, 'Cycle must prevent every apply() call.');

        $failed = array_values(array_filter(
            $result->entries,
            static fn($entry) => $entry->status === ConfigImportEntryResult::STATUS_FAILED,
        ))[0] ?? null;
        self::assertNotNull($failed);
        self::assertNotNull($failed->reason);
        self::assertStringContainsString('Config dependency cycle', $failed->reason);
        // The failed entry's ref is one of the cycle members.
        self::assertContains($failed->ref, ['role.admin', 'role.member']);
    }

    #[Test]
    public function no_dependency_check_bypass_applies_cycle_and_emits_audit_warning(): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        foreach (CycleFixture::files() as $file) {
            $repository->put($file);
        }

        $applied = [];
        $hook = new class ($applied) implements ConfigImportApplyHookInterface {
            /** @param list<string> $applied */
            public function __construct(private array &$applied) {}

            public function apply(ConfigSyncFile $file): string
            {
                $this->applied[] = $file->ref();

                return ConfigImportEntryResult::STATUS_CREATED;
            }

            public function delete(string $ref): void {}
        };

        /** @var list<array{string, string, array<string, mixed>}> $auditLog */
        $auditLog = [];
        $auditor = static function (string $level, string $message, array $context) use (&$auditLog): void {
            $auditLog[] = [$level, $message, $context];
        };

        $importer = new ConfigImporter(
            repository: $repository,
            applyHook: $hook,
            preflight: new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight(),
            auditLogger: $auditor,
        );

        $result = $importer->import(noDependencyCheck: true);

        self::assertSame(0, $result->failureCount(), 'Bypass must not produce failures.');
        self::assertCount(2, $applied, 'Both cycle members must be applied under bypass.');
        $warnings = array_values(array_filter($auditLog, static fn($entry) => $entry[0] === 'warning'));
        self::assertCount(1, $warnings, 'Bypass must emit exactly one audit warning.');
        self::assertStringContainsString('--no-dependency-check', $warnings[0][1]);
    }

    #[Test]
    public function cycle_message_truncation_is_capped_at_hop_limit_for_longer_cycles(): void
    {
        // Beyond the two-hop cycle in CycleFixture: stress the resolver with
        // a six-hop cycle to verify message-rendering behavior on cycles
        // longer than the truncation limit (5 hops + closing element).
        $declarations = [
            'role.a' => ['role.b'],
            'role.b' => ['role.c'],
            'role.c' => ['role.d'],
            'role.d' => ['role.e'],
            'role.e' => ['role.f'],
            'role.f' => ['role.a'],
        ];

        $caught = null;
        try {
            new DependencyResolver()->resolve($declarations);
        } catch (ConfigDependencyCycleException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        // The full path must be intact even if the message truncates it.
        self::assertSame(7, \count($caught->getCycle()), 'Full path length = 6 unique + 1 closing.');
        self::assertSame($caught->getCycle()[0], $caught->getCycle()[6]);
        // Truncated message marker per ConfigDependencyCycleException::MESSAGE_HOP_LIMIT.
        self::assertStringContainsString('…', $caught->getMessage());
    }

}
