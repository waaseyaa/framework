<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Exception\ConfigSerializationException;
use Waaseyaa\Config\Sync\ConfigSyncDeserializer;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;

#[CoversClass(ConfigSyncDeserializer::class)]
final class ConfigSyncDeserializerTest extends TestCase
{
    #[Test]
    public function parsesCanonicalYaml(): void
    {
        $yaml = <<<YAML
            _meta:
              dependencies:
                - role.admin
              entity_id: coordinator
              entity_type: role
              format: waaseyaa.config-sync/1
              langcode: en
              owner_config_contract_version: 1
              owner_package: waaseyaa/config
              schema_hash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
              schema_id: waaseyaa.role
              schema_version: 1
              uuid: 0193abcd-7c4d-7000-8b6e-1a2b3c4d5e6f
            description: 'Coordinators manage community calendars.'
            id: coordinator
            label: Coordinator
            weight: 10

            YAML;

        $file = new ConfigSyncDeserializer()->fromYaml($yaml, 'role.coordinator.yml');

        self::assertSame('role', $file->entityType);
        self::assertSame('coordinator', $file->entityId);
        self::assertSame('0193abcd-7c4d-7000-8b6e-1a2b3c4d5e6f', $file->uuid);
        self::assertSame(['role.admin'], $file->dependencies);
        self::assertSame('en', $file->langcode);
        self::assertSame(10, $file->fields['weight']);
        self::assertSame('Coordinator', $file->fields['label']);
    }

    #[Test]
    public function roundTripsThroughSerializer(): void
    {
        $original = ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'coordinator',
            uuid: '0193abcd-7c4d-7000-8b6e-1a2b3c4d5e6f',
            dependencies: ['role.admin'],
            langcode: 'en',
            fields: [
                'description' => 'Coordinators manage community calendars.',
                'id' => 'coordinator',
                'label' => 'Coordinator',
                'weight' => 10,
            ],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );

        $yaml = new ConfigSyncSerializer()->toYaml($original);
        $parsed = new ConfigSyncDeserializer()->fromYaml($yaml, 'role.coordinator.yml');

        self::assertSame($original->entityType, $parsed->entityType);
        self::assertSame($original->entityId, $parsed->entityId);
        self::assertSame($original->uuid, $parsed->uuid);
        self::assertSame($original->dependencies, $parsed->dependencies);
        self::assertSame($original->langcode, $parsed->langcode);
        self::assertSame($original->fields, $parsed->fields);
        // Hash equality proves canonical-form round-trip.
        self::assertSame($original->contentHash(), $parsed->contentHash());
    }

    #[Test]
    public function detectsFilenameEntityTypeMismatch(): void
    {
        $yaml = <<<YAML
            _meta:
              dependencies: []
              entity_type: permission
              langcode: en
              uuid: u
            label: x

            YAML;

        $this->expectException(ConfigSerializationException::class);
        new ConfigSyncDeserializer()->fromYaml($yaml, 'role.admin.yml');
    }

    #[Test]
    public function rejectsMalformedYaml(): void
    {
        $yaml = "this: is\n  not\nvalid: yaml\n";

        $this->expectException(ConfigSerializationException::class);
        new ConfigSyncDeserializer()->fromYaml($yaml, 'role.admin.yml');
    }

    #[Test]
    public function rejectsNonMappingTopLevel(): void
    {
        $yaml = "- just\n- a\n- list\n";

        $this->expectException(ConfigSerializationException::class);
        new ConfigSyncDeserializer()->fromYaml($yaml, 'role.admin.yml');
    }

    #[Test]
    public function rejectsMissingMetaBlock(): void
    {
        $yaml = "label: x\n";

        $this->expectException(ConfigSerializationException::class);
        new ConfigSyncDeserializer()->fromYaml($yaml, 'role.admin.yml');
    }

    #[Test]
    public function rejectsMissingUuidInMeta(): void
    {
        $yaml = <<<YAML
            _meta:
              entity_type: role
              langcode: en

            YAML;

        $this->expectException(ConfigSerializationException::class);
        new ConfigSyncDeserializer()->fromYaml($yaml, 'role.admin.yml');
    }
}
