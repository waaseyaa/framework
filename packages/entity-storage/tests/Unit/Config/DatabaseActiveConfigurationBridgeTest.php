<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Config\DatabaseActiveConfigurationBridge;
use Waaseyaa\EntityStorage\Config\DatabaseConfigurationGenerationResolver;
use Waaseyaa\EntityStorage\Config\TestingActiveConfigurationBridge;
use Waaseyaa\EntityStorage\Config\TestingConfigurationGenerationResolver;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

final class DatabaseActiveConfigurationBridgeTest extends TestCase
{
    private DBALDatabase $database;
    private ConfigurationAuthorityContext $baseContext;
    private string $generationId;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite(':memory:', 'testing');
        $migration = require dirname(__DIR__, 3) . '/migrations/2026_08_12_000002_configuration_authority.php';
        $migration->up(new SchemaBuilder($this->database->getConnection()));

        $this->baseContext = new ConfigurationAuthorityContext(
            authorityId: str_repeat('a', 64),
            databaseIdentity: 'database:v1:test',
            syncPath: '/tmp/config-sync',
            selectorProvenance: ['default'],
        );
        $this->generationId = str_repeat('b', 64);
    }

    #[Test]
    public function resolverAndBridgePinEveryReadToOneActiveGeneration(): void
    {
        $this->seedActiveGeneration(['name' => 'Waaseyaa', 'slogan' => 'Living knowledge']);
        $context = new DatabaseConfigurationGenerationResolver($this->database)->bind($this->baseContext);

        self::assertSame($this->generationId, $context->activeGenerationId);
        self::assertSame(1, $context->activationSequence);

        $bridge = new DatabaseActiveConfigurationBridge($this->database, $context);
        self::assertSame(['name' => 'Waaseyaa', 'slogan' => 'Living knowledge'], $bridge->activeStorage()->read('system.site'));
        self::assertSame(['system.site'], $bridge->activeStorage()->listAll());

        $files = iterator_to_array($bridge->iterate());
        self::assertCount(1, $files);
        self::assertInstanceOf(ConfigSyncFile::class, $files[0]);
        self::assertSame('system.site', $files[0]->ref());
        self::assertSame('Waaseyaa', $files[0]->fields['name']);
    }

    #[Test]
    public function missingActivationFailsClosedInsteadOfReadingAFileFallback(): void
    {
        $context = new DatabaseConfigurationGenerationResolver($this->database)->bind($this->baseContext);
        self::assertNull($context->activeGenerationId);

        $this->expectException(ConfigurationAuthorityUnavailableException::class);
        $this->expectExceptionMessage('Active configuration generation is unavailable');
        new DatabaseActiveConfigurationBridge($this->database, $context);
    }

    #[Test]
    public function explicitTestingResolverCreatesOnlyItsDeterministicEmptyGeneration(): void
    {
        $resolver = new TestingConfigurationGenerationResolver(
            new DatabaseConfigurationGenerationResolver($this->database),
        );
        $context = $resolver->bind($this->baseContext);

        self::assertSame(TestingConfigurationGenerationResolver::generationId($this->baseContext), $context->activeGenerationId);
        self::assertSame(1, $context->activationSequence);
        self::assertContains('testing-empty-generation', $context->selectorProvenance);

        $bridge = new TestingActiveConfigurationBridge($context);
        self::assertSame([], $bridge->activeStorage()->listAll());
    }

    #[Test]
    public function testingResolverNeverOverridesARealDatabaseActivation(): void
    {
        $this->seedActiveGeneration(['name' => 'Waaseyaa']);
        $resolver = new TestingConfigurationGenerationResolver(
            new DatabaseConfigurationGenerationResolver($this->database),
        );

        $context = $resolver->bind($this->baseContext);
        self::assertSame($this->generationId, $context->activeGenerationId);
        self::assertNotContains('testing-empty-generation', $context->selectorProvenance);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-testing generation');
        new TestingActiveConfigurationBridge($context);
    }

    #[Test]
    public function cfg01RefusesAllActiveMutationUntilSuccessorGatesAreBound(): void
    {
        $this->seedActiveGeneration(['name' => 'Waaseyaa']);
        $context = new DatabaseConfigurationGenerationResolver($this->database)->bind($this->baseContext);
        $bridge = new DatabaseActiveConfigurationBridge($this->database, $context);

        try {
            $bridge->activeStorage()->write('system.site', ['name' => 'Changed']);
            self::fail('Immutable active storage accepted a direct write.');
        } catch (ConfigurationAuthorityUnavailableException $exception) {
            self::assertStringContainsString('CFG-02 transactional activation', $exception->getMessage());
        }

        $file = iterator_to_array($bridge->iterate())[0];
        try {
            $bridge->apply($file);
            self::fail('Bridge accepted an apply without successor gates.');
        } catch (ConfigurationAuthorityUnavailableException $exception) {
            self::assertStringContainsString('CFG-03 validation gates', $exception->getMessage());
        }

        self::assertSame(['name' => 'Waaseyaa'], $bridge->activeStorage()->read('system.site'));
    }

    #[Test]
    public function aPointerToANonActiveGenerationIsRejectedAsCorruptAuthority(): void
    {
        $this->seedActiveGeneration(['name' => 'Waaseyaa']);
        $this->database->query(
            'UPDATE waaseyaa_config_generation SET lifecycle_state = ? WHERE authority_id = ? AND generation_id = ?',
            ['superseded', $this->baseContext->authorityId, $this->generationId],
        );

        $this->expectExceptionMessage('does not identify one matching active generation');
        new DatabaseConfigurationGenerationResolver($this->database)->bind($this->baseContext);
    }

    /** @param array<string, mixed> $fields */
    private function seedActiveGeneration(array $fields): void
    {
        ksort($fields, SORT_STRING);
        $file = new ConfigSyncFile(
            entityType: 'system',
            entityId: 'site',
            uuid: ConfigSyncFile::deterministicUuid('system', 'site'),
            dependencies: [],
            langcode: 'en',
            fields: $fields,
        );
        $this->database->query(
            'INSERT INTO waaseyaa_config_generation '
            . '(authority_id, generation_id, activation_sequence, schema_version, manifest_hash, lifecycle_state, created_at) '
            . 'VALUES (?, ?, 1, ?, ?, ?, ?)',
            [$this->baseContext->authorityId, $this->generationId, 'config-schema.v1', str_repeat('c', 64), 'active', '2026-08-12T00:00:00Z'],
        );
        $this->database->query(
            'INSERT INTO waaseyaa_config_entry '
            . '(authority_id, generation_id, config_name, entity_type, entity_id, uuid, dependencies_json, langcode, fields_json, content_hash) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->baseContext->authorityId,
                $this->generationId,
                $file->ref(),
                $file->entityType,
                $file->entityId,
                $file->uuid,
                '[]',
                $file->langcode,
                json_encode($file->fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $file->contentHash(),
            ],
        );
        $this->database->query(
            'INSERT INTO waaseyaa_config_activation (authority_id, generation_id, activation_sequence, activated_at) '
            . 'VALUES (?, ?, 1, ?)',
            [$this->baseContext->authorityId, $this->generationId, '2026-08-12T00:00:00Z'],
        );
    }
}
