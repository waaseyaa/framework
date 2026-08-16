<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\ConfigManifest;
use Waaseyaa\Config\Exception\ConfigSerializationException;
use Waaseyaa\Config\Schema\ConfigSchemaValidator;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Config\Sync\ConfigSyncFile;

/**
 * Retained-red reproductions for the S1-FW-CFG-03 predecessor.
 *
 * The first implementation slice closes only behaviors exercised here. Later
 * CFG-03 slices add the registry, strict lexical YAML, signed envelope,
 * compatibility, migration, drift, and installed-consumer matrices defined by
 * docs/specs/s1-configuration-schema-manifest.md.
 */
#[CoversClass(ConfigSchemaValidator::class)]
#[CoversClass(ConfigSyncFile::class)]
#[CoversClass(ConfigManifest::class)]
final class S1ConfigurationSchemaAndManifestContractTest extends TestCase
{
    #[Test]
    public function schema_registration_rejects_an_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported configuration schema type "mystery"');

        new ConfigSchemaValidator()->registerSchema('system.site', [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'mystery'],
            ],
        ]);
    }

    #[Test]
    public function schema_registration_rejects_an_unknown_keyword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported configuration schema keyword "coerce"');

        new ConfigSchemaValidator()->registerSchema('system.site', [
            'type' => 'object',
            'coerce' => true,
            'properties' => [],
        ]);
    }

    #[Test]
    public function objects_are_closed_by_default(): void
    {
        $violations = new ConfigSchemaValidator()->validate(
            ['title' => 'Waaseyaa', 'undeclared' => true],
            [
                'type' => 'object',
                'properties' => ['title' => ['type' => 'string']],
            ],
        );

        self::assertCount(1, $violations);
        self::assertSame('undeclared', $violations[0]->path);
        self::assertStringContainsString('not declared', $violations[0]->message);
    }

    #[Test]
    public function array_items_are_validated_recursively(): void
    {
        $violations = new ConfigSchemaValidator()->validate(
            ['servers' => [['enabled' => 'yes']]],
            [
                'type' => 'object',
                'properties' => [
                    'servers' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'enabled' => ['type' => 'boolean'],
                            ],
                        ],
                    ],
                ],
            ],
        );

        self::assertCount(1, $violations);
        self::assertSame('servers.0.enabled', $violations[0]->path);
    }

    #[Test]
    public function recursive_defaults_are_materialized_into_a_separate_effective_document(): void
    {
        $authored = ['title' => 'Waaseyaa'];
        $schema = [
            'type' => 'object',
            'properties' => [
                'features' => [
                    'type' => 'object',
                    'default' => [],
                    'properties' => [
                        'enabled' => ['type' => 'boolean', 'default' => false],
                    ],
                ],
                'title' => ['type' => 'string'],
            ],
        ];

        $effective = new ConfigSchemaValidator()->materialize($authored, $schema);

        self::assertSame(['title' => 'Waaseyaa'], $authored);
        self::assertSame([
            'title' => 'Waaseyaa',
            'features' => ['enabled' => false],
        ], $effective);
    }

    #[Test]
    public function sync_input_requires_the_current_format_and_schema_identity(): void
    {
        $this->expectException(ConfigSerializationException::class);
        $this->expectExceptionMessage('format');

        ConfigSyncFile::fromParsedArray([
            '_meta' => [
                'dependencies' => [],
                'entity_type' => 'system',
                'langcode' => 'en',
                'uuid' => '0193abc',
            ],
            'title' => 'Waaseyaa',
        ], 'system.site.yml');
    }

    #[Test]
    public function dependency_metadata_is_never_coerced_to_an_empty_list(): void
    {
        $this->expectException(ConfigSerializationException::class);
        $this->expectExceptionMessage('dependencies');

        ConfigSyncFile::fromParsedArray($this->versionedPayload([
            'dependencies' => 'role.admin',
        ]), 'system.site.yml');
    }

    #[Test]
    public function unknown_sync_metadata_is_rejected(): void
    {
        $this->expectException(ConfigSerializationException::class);
        $this->expectExceptionMessage('unknown _meta key "surprise"');

        ConfigSyncFile::fromParsedArray($this->versionedPayload([
            'surprise' => true,
        ]), 'system.site.yml');
    }

    #[Test]
    public function nested_map_order_does_not_change_manifest_checksums(): void
    {
        $left = new MemoryStorage();
        $left->write('system.site', [
            'nested' => ['alpha' => 1, 'beta' => 2],
        ]);

        $right = new MemoryStorage();
        $right->write('system.site', [
            'nested' => ['beta' => 2, 'alpha' => 1],
        ]);

        $leftManifest = new ConfigManifest($left)->generate();
        $rightManifest = new ConfigManifest($right)->generate();

        self::assertSame($leftManifest['configs'], $rightManifest['configs']);
        self::assertSame($leftManifest['checksum'], $rightManifest['checksum']);
    }

    #[Test]
    public function verification_rejects_a_tampered_global_manifest_checksum(): void
    {
        $storage = new MemoryStorage();
        $storage->write('system.site', ['title' => 'Waaseyaa']);
        $manifest = new ConfigManifest($storage);
        $manifest->generateAndSave(['waaseyaa/config' => '3']);

        $stored = $storage->read(ConfigManifest::MANIFEST_NAME);
        self::assertIsArray($stored);
        $stored['checksum'] = 'sha256:' . str_repeat('0', 64);
        $storage->write(ConfigManifest::MANIFEST_NAME, $stored);

        $result = $manifest->verify();

        self::assertFalse($result->isValid);
        self::assertSame('Manifest aggregate checksum mismatch.', $result->error);
    }

    /**
     * @param array<string, mixed> $metaOverrides
     * @return array<string, mixed>
     */
    private function versionedPayload(array $metaOverrides = []): array
    {
        return [
            '_meta' => array_replace([
                'dependencies' => [],
                'entity_id' => 'site',
                'entity_type' => 'system',
                'format' => 'waaseyaa.config-sync/1',
                'langcode' => 'en',
                'owner_config_contract_version' => 1,
                'owner_package' => 'waaseyaa/config',
                'schema_hash' => 'sha256:' . str_repeat('a', 64),
                'schema_id' => 'waaseyaa.system.site',
                'schema_version' => 1,
                'uuid' => '0193abc',
            ], $metaOverrides),
            'title' => 'Waaseyaa',
        ];
    }
}
