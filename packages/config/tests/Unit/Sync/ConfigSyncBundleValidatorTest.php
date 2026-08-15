<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigBundleDiagnostic;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidationResult;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidator;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Config\Sync\ValidatedConfigSyncEntry;

#[CoversClass(ConfigSyncBundleValidator::class)]
#[CoversClass(ConfigSyncBundleValidationResult::class)]
#[CoversClass(ConfigBundleDiagnostic::class)]
#[CoversClass(ValidatedConfigSyncEntry::class)]
final class ConfigSyncBundleValidatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/waaseyaa_cfg03_bundle_' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $path = $this->directory . '/' . $name;
                is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
            }
        }
        rmdir($this->directory);
    }

    #[Test]
    public function validBundleBindsExactAuthoredEffectiveAndDependencies(): void
    {
        [$registry, $registration] = $this->registry();
        $this->write($this->file('role', 'admin', [], $registration));
        $this->write($this->file('role', 'editor', ['role.admin'], $registration));

        $result = new ConfigSyncBundleValidator($registry)->validate($this->directory);

        self::assertTrue($result->isValid());
        self::assertSame(['role.admin@en', 'role.editor@en'], array_map(
            static fn(ValidatedConfigSyncEntry $entry): string => $entry->key(),
            $result->requireValidEntries(),
        ));
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $result->entries[0]->hashes->exactByteHash);
    }

    #[Test]
    public function everyMalformedDirectoryMemberIsReportedWithoutMutationOrTruncation(): void
    {
        [$registry] = $this->registry();
        file_put_contents($this->directory . '/a.txt', "ignored\n");
        file_put_contents($this->directory . '/role.empty.yml', '');
        file_put_contents($this->directory . '/role.invalid.yml', "_meta:\n  format: wrong\n");
        mkdir($this->directory . '/role.nested.yml');
        $before = $this->snapshot();

        $result = new ConfigSyncBundleValidator($registry)->validate($this->directory);

        self::assertFalse($result->isValid());
        self::assertCount(4, $result->diagnostics);
        self::assertSame(['a.txt', 'role.empty.yml', 'role.invalid.yml', 'role.nested.yml'], array_column(
            array_map(static fn(ConfigBundleDiagnostic $diagnostic): array => $diagnostic->toArray(), $result->diagnostics),
            'path',
        ));
        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function duplicateUuidAndMissingDependencyAreBothReported(): void
    {
        [$registry, $registration] = $this->registry();
        $uuid = ConfigSyncFile::deterministicUuid('role', 'shared');
        $this->write($this->file('role', 'admin', ['role.missing'], $registration, $uuid));
        $this->write($this->file('role', 'editor', [], $registration, $uuid));

        $result = new ConfigSyncBundleValidator($registry)->validate($this->directory);
        $fields = array_map(static fn(ConfigBundleDiagnostic $diagnostic): string => $diagnostic->field, $result->diagnostics);

        self::assertContains('dependencies', $fields);
        self::assertContains('uuid', $fields);
        $this->expectException(\LogicException::class);
        $result->requireValidEntries();
    }

    /** @return array{ConfigSchemaRegistry, \Waaseyaa\Config\Schema\ConfigSchemaRegistration} */
    private function registry(): array
    {
        $registry = new ConfigSchemaRegistry();
        $registration = $registry->register('waaseyaa.role', 1, 'waaseyaa/config', 1, [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => ['label' => ['type' => 'string']],
            'required' => ['label'],
        ]);
        $registry->freeze();

        return [$registry, $registration];
    }

    private function file(
        string $entityType,
        string $entityId,
        array $dependencies,
        \Waaseyaa\Config\Schema\ConfigSchemaRegistration $registration,
        ?string $uuid = null,
    ): ConfigSyncFile {
        return ConfigSyncFile::writable(
            entityType: $entityType,
            entityId: $entityId,
            uuid: $uuid ?? ConfigSyncFile::deterministicUuid($entityType, $entityId),
            dependencies: $dependencies,
            langcode: 'en',
            fields: ['label' => ucfirst($entityId)],
            schemaId: $registration->schemaId,
            schemaVersion: $registration->schemaVersion,
            schemaHash: $registration->canonicalSchemaHash,
            ownerPackage: $registration->ownerPackage,
            ownerConfigContractVersion: $registration->ownerConfigContractVersion,
        );
    }

    private function write(ConfigSyncFile $file): void
    {
        file_put_contents($this->directory . '/' . $file->filename(), new ConfigSyncSerializer()->toYaml($file));
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (scandir($this->directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->directory . '/' . $name;
            $snapshot[$name] = is_file($path) ? hash_file('sha256', $path) : 'directory';
        }

        return $snapshot;
    }
}
