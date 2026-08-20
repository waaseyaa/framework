<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigPackageContract;
use Waaseyaa\Config\Schema\ConfigSchemaRegistration;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidator;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Workflows\Config\WorkflowAssignmentsConfig;
use Waaseyaa\Workflows\Config\WorkflowAssignmentsSemanticValidator;
use Waaseyaa\Workflows\WorkflowServiceProvider;

#[CoversClass(WorkflowAssignmentsConfig::class)]
#[CoversClass(WorkflowAssignmentsSemanticValidator::class)]
final class WorkflowAssignmentsConfigTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/workflow-assignments-config-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0o700, true));
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) ?: [] as $name) {
            if (!in_array($name, ['.', '..'], true)) {
                @unlink($this->directory.'/'.$name);
            }
        }
        @rmdir($this->directory);
    }

    #[Test]
    public function package_manifest_declares_the_schema_owner_contract(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($composer);

        $contract = ConfigPackageContract::fromComposerManifest($composer);

        self::assertSame(WorkflowAssignmentsConfig::OWNER_PACKAGE, $contract->package);
        self::assertSame(WorkflowAssignmentsConfig::OWNER_CONFIG_CONTRACT_VERSION, $contract->writableContractVersion);
        self::assertSame([WorkflowAssignmentsConfig::OWNER_CONFIG_CONTRACT_VERSION], $contract->readableContractVersions);
        self::assertSame(WorkflowServiceProvider::class, $contract->schemaProvider);
    }

    #[Test]
    public function strict_assignment_entry_validates_and_is_writable_by_the_installed_contract(): void
    {
        $registry = new ConfigSchemaRegistry();
        $registration = WorkflowAssignmentsConfig::register($registry);
        $registry->freeze();
        $file = $this->file($registration, ['node.page' => 'editorial']);
        file_put_contents($this->directory.'/'.$file->filename(), new ConfigSyncSerializer()->toYaml($file));

        $result = new ConfigSyncBundleValidator($registry)->validate($this->directory);
        self::assertTrue($result->isValid(), implode("\n", array_map(
            static fn($diagnostic): string => $diagnostic->message,
            $result->diagnostics,
        )));

        $compatibility = new ConfigPackageCompatibility([
            new ConfigPackageContract(
                WorkflowAssignmentsConfig::OWNER_PACKAGE,
                WorkflowAssignmentsConfig::OWNER_CONFIG_CONTRACT_VERSION,
                WorkflowServiceProvider::class,
                [WorkflowAssignmentsConfig::OWNER_CONFIG_CONTRACT_VERSION],
            ),
        ]);
        $compatibility->assertWritable($file, $registry);
        self::addToAssertionCount(1);
    }

    #[Test]
    public function non_string_assignment_value_is_rejected_by_the_closed_schema(): void
    {
        $registry = new ConfigSchemaRegistry();
        $registration = WorkflowAssignmentsConfig::register($registry);
        $registry->freeze();
        $file = $this->file($registration, ['node.page' => true]);
        file_put_contents($this->directory.'/'.$file->filename(), new ConfigSyncSerializer()->toYaml($file));

        $result = new ConfigSyncBundleValidator($registry)->validate($this->directory);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('Expected type "string"', $result->diagnostics[0]->message);
    }

    #[Test]
    public function wrong_package_identity_remains_fail_closed(): void
    {
        $registry = new ConfigSchemaRegistry();
        $registration = WorkflowAssignmentsConfig::register($registry);
        $registry->freeze();
        $file = ConfigSyncFile::writable(
            entityType: 'workflows',
            entityId: 'assignments',
            uuid: ConfigSyncFile::deterministicUuid('workflows', 'assignments'),
            dependencies: [],
            langcode: 'en',
            fields: ['node.page' => 'editorial'],
            schemaId: $registration->schemaId,
            schemaVersion: $registration->schemaVersion,
            schemaHash: $registration->canonicalSchemaHash,
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
        file_put_contents($this->directory.'/'.$file->filename(), new ConfigSyncSerializer()->toYaml($file));

        $result = new ConfigSyncBundleValidator($registry)->validate($this->directory);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('schema or package identity does not match', $result->diagnostics[0]->message);
    }

    #[Test]
    public function semantic_assignment_validation_rejects_a_non_revisionable_binding(): void
    {
        $manager = new EntityTypeManager(new SymfonyEventDispatcherAdapter());
        $manager->registerEntityType(new EntityType(
            id: 'note',
            label: 'Note',
            class: \stdClass::class,
            keys: ['id' => 'id'],
            revisionable: false,
        ));
        $registry = new ConfigSchemaRegistry();
        $registration = WorkflowAssignmentsConfig::register($registry, $manager);
        $registry->freeze();
        $file = $this->file($registration, ['note.note' => 'editorial']);
        file_put_contents($this->directory.'/'.$file->filename(), new ConfigSyncSerializer()->toYaml($file));

        $result = new ConfigSyncBundleValidator($registry)->validate($this->directory);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('not revisionable', $result->diagnostics[0]->message);
    }

    #[Test]
    public function semantic_assignment_validation_rejects_noncanonical_key_and_workflow_id(): void
    {
        $registry = new ConfigSchemaRegistry();
        $registration = WorkflowAssignmentsConfig::register(
            $registry,
            new EntityTypeManager(new SymfonyEventDispatcherAdapter()),
        );
        $registry->freeze();
        $file = $this->file($registration, ['node' => 'Editorial Workflow']);
        file_put_contents($this->directory.'/'.$file->filename(), new ConfigSyncSerializer()->toYaml($file));

        $result = new ConfigSyncBundleValidator($registry)->validate($this->directory);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('canonical', $result->diagnostics[0]->message);
    }

    /** @param array<string, mixed> $fields */
    private function file(ConfigSchemaRegistration $registration, array $fields): ConfigSyncFile
    {
        return ConfigSyncFile::writable(
            entityType: 'workflows',
            entityId: 'assignments',
            uuid: ConfigSyncFile::deterministicUuid('workflows', 'assignments'),
            dependencies: [],
            langcode: 'en',
            fields: $fields,
            schemaId: $registration->schemaId,
            schemaVersion: $registration->schemaVersion,
            schemaHash: $registration->canonicalSchemaHash,
            ownerPackage: $registration->ownerPackage,
            ownerConfigContractVersion: $registration->ownerConfigContractVersion,
        );
    }
}
