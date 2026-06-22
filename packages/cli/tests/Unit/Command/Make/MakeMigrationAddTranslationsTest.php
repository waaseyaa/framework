<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\AddTranslationsMigrationGenerator;
use Waaseyaa\CLI\Handler\MakeMigrationHandler;
use Waaseyaa\CLI\Handler\MissingLangcodeColumnException;
use Waaseyaa\CLI\Provider\MakeServiceProviderA;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;

#[CoversClass(MakeMigrationHandler::class)]
#[CoversClass(AddTranslationsMigrationGenerator::class)]
#[CoversClass(MissingLangcodeColumnException::class)]
final class MakeMigrationAddTranslationsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_make_addtr_test_' . uniqid('', true);
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function it_fails_fast_when_default_langcode_is_missing(): void
    {
        $manager = $this->buildManager('article', translatable: true, backend: 'sql-column');
        $tester = $this->createTester($manager);

        $tester->execute(['unused_name', '--add-translations=article']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('--default-langcode option is required', $tester->getStderr());
    }

    #[Test]
    public function it_fails_when_entity_type_manager_is_unavailable(): void
    {
        $tester = $this->createTester(null);

        $tester->execute(['unused_name', '--add-translations=article', '--default-langcode=en']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('EntityTypeManager is not available', $tester->getStderr());
    }

    #[Test]
    public function it_fails_when_entity_type_is_not_registered(): void
    {
        $tester = $this->createTester($this->emptyManager());

        $tester->execute(['unused_name', '--add-translations=ghost', '--default-langcode=en']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('is not registered', $tester->getStderr());
    }

    #[Test]
    public function it_fails_for_config_entities(): void
    {
        $manager = $this->buildManager('role', translatable: false, backend: 'sql-blob', isConfig: true);
        $tester = $this->createTester($manager);

        $tester->execute(['unused_name', '--add-translations=role', '--default-langcode=en']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('appears to be a config entity', $tester->getStderr());
    }

    #[Test]
    public function it_fails_when_no_fields_are_translatable_for_sql_column(): void
    {
        $manager = $this->buildManager('article', translatable: false, backend: 'sql-column');
        $tester = $this->createTester($manager);

        $tester->execute(['unused_name', '--add-translations=article', '--default-langcode=en']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('No fields are marked translatable', $tester->getStderr());
    }

    #[Test]
    public function it_generates_a_sql_column_forward_and_down_migration(): void
    {
        $manager = $this->buildManager('article', translatable: true, backend: 'sql-column');
        $tester = $this->createTester($manager);

        $tester->execute(['unused_name', '--add-translations=article', '--default-langcode=en']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $content = $this->readGeneratedMigration();

        self::assertStringContainsString("private const TABLE = 'article'", $content);
        self::assertStringContainsString("private const TRANSLATIONS_TABLE = 'article_translations'", $content);
        self::assertStringContainsString("private const DEFAULT_LANGCODE = 'en'", $content);
        self::assertStringContainsString("private const BACKEND = 'sql-column'", $content);
        self::assertStringContainsString("'title'", $content);
        self::assertStringContainsString('CREATE TABLE %s', $content);
        self::assertStringContainsString('INSERT INTO %s', $content);
        self::assertStringContainsString('ALTER TABLE %s DROP COLUMN %s', $content);
        self::assertStringContainsString('ADD COLUMN default_langcode VARCHAR(12)', $content);

        // Data-loss warning lives in the DOWN docblock, not just runtime.
        self::assertMatchesRegularExpression('/\*\s+DATA LOSS WARNING/m', $content);
        self::assertStringContainsString('DROP TABLE', $content);
    }

    #[Test]
    public function it_generates_a_sql_blob_forward_and_down_migration(): void
    {
        $manager = $this->buildManager('article', translatable: true, backend: 'sql-blob');
        $tester = $this->createTester($manager);

        $tester->execute(['unused_name', '--add-translations=article', '--default-langcode=fr']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $content = $this->readGeneratedMigration();

        self::assertStringContainsString("private const BACKEND = 'sql-blob'", $content);
        self::assertStringContainsString("private const DEFAULT_LANGCODE = 'fr'", $content);
        self::assertStringContainsString('ADD COLUMN default_langcode VARCHAR(12)', $content);
        self::assertStringContainsString('UPDATE %s SET langcode', $content);
        self::assertStringContainsString('PRIMARY KEY (entity_id, langcode)', $content);
        self::assertStringContainsString('CREATE UNIQUE INDEX', $content);
        self::assertStringContainsString("\$platform === 'mysql'", $content);
        self::assertMatchesRegularExpression('/\*\s+DATA LOSS WARNING/m', $content);
        self::assertStringContainsString('DROP INDEX', $content);
        self::assertStringContainsString('DROP COLUMN default_langcode', $content);
    }

    #[Test]
    public function generated_migration_file_is_valid_php_and_returns_a_migration(): void
    {
        $manager = $this->buildManager('article', translatable: true, backend: 'sql-column');
        $tester = $this->createTester($manager);

        $tester->execute(['unused_name', '--add-translations=article', '--default-langcode=en']);
        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());

        $migrationPath = $this->getGeneratedMigrationPath();
        $instance = require $migrationPath;
        self::assertInstanceOf(\Waaseyaa\Foundation\Migration\Migration::class, $instance);
    }

    #[Test]
    public function missing_langcode_column_exception_carries_table_name(): void
    {
        $exception = new MissingLangcodeColumnException('article');
        self::assertStringContainsString('"article"', $exception->getMessage());
        self::assertStringContainsString('langcode', $exception->getMessage());
    }

    private function emptyManager(): EntityTypeManagerInterface
    {
        return new class implements EntityTypeManagerInterface {
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                throw new \RuntimeException('not used in this test path');
            }

            public function getDefinitions(): array
            {
                return [];
            }

            public function hasDefinition(string $entityTypeId): bool
            {
                return false;
            }

            public function getStorage(string $entityTypeId): EntityStorageInterface
            {
                throw new \RuntimeException('not used in this test path');
            }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                throw new \RuntimeException('not used in this test path');
            }

            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void
            {
                throw new \RuntimeException('not used in this test path');
            }

            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void
            {
                throw new \RuntimeException('not used in this test path');
            }
        };
    }

    private function buildManager(string $entityTypeId, bool $translatable, string $backend, bool $isConfig = false): EntityTypeManagerInterface
    {
        $title = new FieldDefinition(name: 'title', type: 'string', translatable: $translatable);
        $body = new FieldDefinition(name: 'body', type: 'text', translatable: $translatable);
        $weight = new FieldDefinition(name: 'weight', type: 'integer');
        $fields = ['title' => $title, 'body' => $body, 'weight' => $weight];

        $keys = $isConfig
            ? ['id' => 'id', 'label' => 'label']
            : ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'langcode' => 'langcode'];

        $entityType = new class ($entityTypeId, $keys, $fields, $backend, $translatable) implements EntityTypeInterface {
            /**
             * @param array<string, string>                   $keys
             * @param array<string, FieldDefinitionInterface> $fields
             */
            public function __construct(
                private readonly string $id,
                private readonly array $keys,
                private readonly array $fields,
                private readonly string $backend,
                private readonly bool $translatable,
            ) {}

            public function id(): string
            {
                return $this->id;
            }

            public function getLabel(): string
            {
                return ucfirst($this->id);
            }

            public function getClass(): string
            {
                return \stdClass::class;
            }

            public function getStorageClass(): string
            {
                return EntityStorageInterface::class;
            }

            public function getKeys(): array
            {
                return $this->keys;
            }

            public function isRevisionable(): bool
            {
                return false;
            }

            public function getRevisionDefault(): bool
            {
                return false;
            }

            public function isTranslatable(): bool
            {
                return $this->translatable;
            }

            public function getBundleEntityType(): ?string
            {
                return null;
            }

            public function getConstraints(): array
            {
                return [];
            }

            public function getFieldDefinitions(): array
            {
                return $this->fields;
            }

            public function getPrimaryStorageBackend(): ?string
            {
                return $this->backend;
            }

            public function getGroup(): ?string
            {
                return null;
            }

            public function getDescription(): ?string
            {
                return null;
            }

            public function getTenancy(): ?array
            {
                return null;
            }
        };

        return new class ($entityTypeId, $entityType) implements EntityTypeManagerInterface {
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function __construct(
                private readonly string $expectedId,
                private readonly EntityTypeInterface $entityType,
            ) {}

            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                if ($entityTypeId !== $this->expectedId) {
                    throw new \RuntimeException("Unknown entity type: {$entityTypeId}");
                }

                return $this->entityType;
            }

            public function getDefinitions(): array
            {
                return [$this->expectedId => $this->entityType];
            }

            public function hasDefinition(string $entityTypeId): bool
            {
                return $entityTypeId === $this->expectedId;
            }

            public function getStorage(string $entityTypeId): EntityStorageInterface
            {
                throw new \RuntimeException('not used in this test path');
            }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                throw new \RuntimeException('not used in this test path');
            }

            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void
            {
                throw new \RuntimeException('not used in this test path');
            }

            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void
            {
                throw new \RuntimeException('not used in this test path');
            }
        };
    }

    private function createTester(?EntityTypeManagerInterface $manager): CliTester
    {
        $provider = new MakeServiceProviderA();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'make:migration') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $tempDir = $this->tempDir;
        $container = new class ($tempDir, $manager) implements ContainerInterface {
            public function __construct(
                private readonly string $projectRoot,
                private readonly ?EntityTypeManagerInterface $manager,
            ) {}

            public function get(string $id): mixed
            {
                if ($id === MakeMigrationHandler::class) {
                    return new MakeMigrationHandler(
                        projectRoot: $this->projectRoot,
                        manifest: null,
                        entityTypeManager: $this->manager,
                    );
                }
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MakeMigrationHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }

    private function readGeneratedMigration(): string
    {
        return (string) file_get_contents($this->getGeneratedMigrationPath());
    }

    private function getGeneratedMigrationPath(): string
    {
        $files = glob($this->tempDir . '/migrations/*.php') ?: [];
        self::assertNotEmpty($files, 'Expected a migration file to be written');

        return $files[0];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $item_path = $dir . '/' . $item;
            is_dir($item_path) ? $this->removeDir($item_path) : unlink($item_path);
        }
        rmdir($dir);
    }
}
