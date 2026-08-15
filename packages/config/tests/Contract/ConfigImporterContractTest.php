<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Sync\ConfigImportApplyHookInterface;
use Waaseyaa\Config\Sync\ConfigImportEntryResult;
use Waaseyaa\Config\Sync\ConfigImporter;
use Waaseyaa\Config\Sync\ConfigImportResult;
use Waaseyaa\Config\Sync\ConfigSyncFile;

/**
 * Contract test for the `config:import` stable surface
 * (`kitty-specs/config-management-v1-01KRCDEC/contracts/cli-namespace.md`).
 *
 * Verifies the FQCNs, method signatures, and status constants that callers
 * outside the package may rely on. Marked `@CoversNothing` because contract
 * tests document API shape, not a single implementation — concrete behavior
 * lives in `ConfigImporterTest`.
 */
#[CoversNothing]
final class ConfigImporterContractTest extends TestCase
{
    #[Test]
    public function importer_fqcn_is_stable(): void
    {
        self::assertTrue(\class_exists(ConfigImporter::class));
        self::assertSame(
            'Waaseyaa\\Config\\Sync\\ConfigImporter',
            ConfigImporter::class,
        );
    }

    #[Test]
    public function import_method_signature_is_stable(): void
    {
        $reflection = new \ReflectionMethod(ConfigImporter::class, 'import');
        self::assertTrue($reflection->isPublic());

        $expectedFlags = ['dryRun', 'deleteOrphans', 'haltOnError', 'noDependencyCheck', 'activeRefs'];
        $actual = array_map(static fn(\ReflectionParameter $p) => $p->getName(), $reflection->getParameters());
        foreach ($expectedFlags as $flag) {
            self::assertContains($flag, $actual, "Importer::import() must accept named parameter '{$flag}'.");
        }

        $returnType = $reflection->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(ConfigImportResult::class, $returnType->getName());
    }

    #[Test]
    public function apply_hook_interface_fqcn_is_stable(): void
    {
        self::assertTrue(\interface_exists(ConfigImportApplyHookInterface::class));
        self::assertSame(
            'Waaseyaa\\Config\\Sync\\ConfigImportApplyHookInterface',
            ConfigImportApplyHookInterface::class,
        );
    }

    #[Test]
    public function apply_hook_methods_are_stable(): void
    {
        $reflection = new \ReflectionClass(ConfigImportApplyHookInterface::class);
        self::assertTrue($reflection->hasMethod('apply'));
        self::assertTrue($reflection->hasMethod('delete'));

        $apply = $reflection->getMethod('apply');
        self::assertCount(1, $apply->getParameters());
        $applyArg = $apply->getParameters()[0];
        $applyArgType = $applyArg->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $applyArgType);
        self::assertSame(ConfigSyncFile::class, $applyArgType->getName());

        $applyReturn = $apply->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $applyReturn);
        self::assertSame('string', $applyReturn->getName());
    }

    #[Test]
    public function entry_result_status_constants_are_stable(): void
    {
        self::assertSame('created', ConfigImportEntryResult::STATUS_CREATED);
        self::assertSame('updated', ConfigImportEntryResult::STATUS_UPDATED);
        self::assertSame('unchanged', ConfigImportEntryResult::STATUS_UNCHANGED);
        self::assertSame('deleted', ConfigImportEntryResult::STATUS_DELETED);
        self::assertSame('failed', ConfigImportEntryResult::STATUS_FAILED);
    }

    #[Test]
    public function result_summary_format_matches_cli_namespace_contract(): void
    {
        $result = new ConfigImportResult(entries: [
            new ConfigImportEntryResult(ref: 'a.b', status: ConfigImportEntryResult::STATUS_CREATED),
        ]);
        self::assertMatchesRegularExpression(
            '/^\d+ created, \d+ updated, \d+ deleted, \d+ failed, \d+ unchanged\.$/',
            $result->summary(),
        );
    }

    #[Test]
    public function implementations_may_satisfy_the_hook_contract(): void
    {
        $hook = new class implements ConfigImportApplyHookInterface {
            public function apply(ConfigSyncFile $file): string
            {
                return ConfigImportEntryResult::STATUS_UPDATED;
            }

            public function delete(string $ref): void {}
        };

        $file = ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'admin',
            uuid: ConfigSyncFile::deterministicUuid('role', 'admin'),
            dependencies: [],
            langcode: 'en',
            fields: [],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );

        self::assertSame(ConfigImportEntryResult::STATUS_UPDATED, $hook->apply($file));
    }
}
