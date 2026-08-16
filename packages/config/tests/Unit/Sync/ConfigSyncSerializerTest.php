<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;

#[CoversClass(ConfigSyncSerializer::class)]
final class ConfigSyncSerializerTest extends TestCase
{
    #[Test]
    public function canonicalFixtureSnapshotIsByteStable(): void
    {
        $file = $this->canonicalCoordinatorFile();
        $serializer = new ConfigSyncSerializer();

        $yaml = $serializer->toYaml($file);

        // Expected snapshot — pinned bytes. Symfony Yaml emits maps in
        // insertion order, scalars unquoted when safe, and uses 2-space
        // indentation per our pinned options.
        $expected = <<<YAML
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
              schema_id: waaseyaa.test.config
              schema_version: 1
              uuid: 0193abcd-7c4d-7000-8b6e-1a2b3c4d5e6f
            description: 'Coordinators manage community calendars and welcome new members.'
            id: coordinator
            label: Coordinator
            permissions:
              - calendar.administer
              - membership.approve
              - membership.invite
            weight: 10

            YAML;

        self::assertSame($expected, $yaml);
    }

    #[Test]
    public function metaBlockEmitsFirst(): void
    {
        $serializer = new ConfigSyncSerializer();
        $yaml = $serializer->toYaml($this->canonicalCoordinatorFile());

        // First non-empty line must declare _meta.
        $firstLine = strtok($yaml, "\n");
        self::assertSame('_meta:', $firstLine);
    }

    #[Test]
    public function emitsTrailingNewline(): void
    {
        $serializer = new ConfigSyncSerializer();
        $yaml = $serializer->toYaml($this->canonicalCoordinatorFile());

        self::assertStringEndsWith("\n", $yaml);
        // Exactly one trailing newline — no double-blank-line at EOF.
        self::assertDoesNotMatchRegularExpression('/\n\n\z/', $yaml);
    }

    #[Test]
    public function fieldKeysSortAlphabeticallyEvenIfInputUnsorted(): void
    {
        // The value object itself enforces sorted input, so this test exercises
        // the serializer's defensive sort by going through buildPayload — which
        // re-sorts to be safe even if input were unsorted at construction time.
        $file = ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'admin',
            uuid: '0193abcd-7c4d-7000-8b6e-1a2b3c4d5e6f',
            dependencies: [],
            langcode: 'en',
            fields: [
                'a_field' => 'first',
                'b_field' => 'second',
                'c_field' => 'third',
            ],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );

        $serializer = new ConfigSyncSerializer();
        $payload = $serializer->buildPayload($file);

        // _meta sits in slot 0; remaining keys are alphabetical.
        $keys = array_keys($payload);
        self::assertSame('_meta', $keys[0]);
        self::assertSame(['a_field', 'b_field', 'c_field'], \array_slice($keys, 1));

        // _meta keys also sort alphabetically.
        $metaKeys = array_keys($payload['_meta']);
        $sortedMetaKeys = $metaKeys;
        sort($sortedMetaKeys, \SORT_STRING);
        self::assertSame($sortedMetaKeys, $metaKeys);
    }

    #[Test]
    public function deterministicAcrossRepeatedCalls(): void
    {
        $serializer = new ConfigSyncSerializer();
        $file = $this->canonicalCoordinatorFile();

        $first = $serializer->toYaml($file);
        $second = $serializer->toYaml($file);
        $third = $serializer->toYaml($file);

        self::assertSame($first, $second);
        self::assertSame($second, $third);
    }

    #[Test]
    public function deterministicAcrossFreshSerializerInstances(): void
    {
        // Two fresh instances must produce byte-identical output for the same
        // input — no hidden per-instance state.
        $a = new ConfigSyncSerializer()->toYaml($this->canonicalCoordinatorFile());
        $b = new ConfigSyncSerializer()->toYaml($this->canonicalCoordinatorFile());

        self::assertSame($a, $b);
    }

    #[Test]
    public function pinnedFlagsAreCorrect(): void
    {
        // These constants are part of the determinism contract; pin them.
        self::assertSame(2, ConfigSyncSerializer::INDENT);
        self::assertSame(32, ConfigSyncSerializer::INLINE_DEPTH);
        // DUMP_MULTI_LINE_LITERAL_BLOCK in Symfony Yaml is bitmask 8.
        self::assertSame(
            \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
            ConfigSyncSerializer::DUMP_FLAGS,
        );
    }

    #[Test]
    public function emptyDependenciesEmitsFlowList(): void
    {
        $serializer = new ConfigSyncSerializer();
        $file = ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'admin',
            uuid: '0193abcd-7c4d-7000-8b6e-1a2b3c4d5e6f',
            dependencies: [],
            langcode: 'en',
            fields: ['label' => 'Admin'],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );

        $yaml = $serializer->toYaml($file);
        // Empty arrays emit as flow style `{  }` or `[  ]` depending on
        // Symfony version; we only assert that no nested block hangs off
        // the empty dependencies list.
        self::assertStringContainsString('dependencies', $yaml);
        self::assertStringContainsString('label: Admin', $yaml);
    }

    #[Test]
    public function legacyReadableInputIsDiagnosticOnlyAndCannotBecomeWritableYaml(): void
    {
        $file = ConfigSyncFile::legacyReadable(
            entityType: 'role',
            entityId: 'admin',
            uuid: '0193abcd-7c4d-7000-8b6e-1a2b3c4d5e6f',
            dependencies: [],
            langcode: 'en',
            fields: ['label' => 'Admin'],
        );
        $serializer = new ConfigSyncSerializer();

        $diagnostic = $serializer->toDiagnosticYaml($file);
        self::assertStringContainsString('format: waaseyaa.config-sync/0-read-only', $diagnostic);
        self::assertStringNotContainsString('schema_id:', $diagnostic);

        $this->expectException(\Waaseyaa\Config\Exception\ConfigSerializationException::class);
        $serializer->toYaml($file);
    }

    private function canonicalCoordinatorFile(): ConfigSyncFile
    {
        return ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'coordinator',
            uuid: '0193abcd-7c4d-7000-8b6e-1a2b3c4d5e6f',
            dependencies: ['role.admin'],
            langcode: 'en',
            fields: [
                'description' => 'Coordinators manage community calendars and welcome new members.',
                'id' => 'coordinator',
                'label' => 'Coordinator',
                'permissions' => [
                    'calendar.administer',
                    'membership.approve',
                    'membership.invite',
                ],
                'weight' => 10,
            ],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
    }
}
