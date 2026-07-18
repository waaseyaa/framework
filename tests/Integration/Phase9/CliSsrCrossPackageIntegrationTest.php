<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase9;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\CLI\Handler\CacheClearHandler;
use Waaseyaa\CLI\Handler\ConfigExportHandler;
use Waaseyaa\CLI\Handler\ConfigImportHandler;
use Waaseyaa\CLI\Handler\EntityCreateHandler;
use Waaseyaa\CLI\Provider\ConfigCacheDbAuditServiceProvider;
use Waaseyaa\CLI\Provider\EntityTypeServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\ConfigManager;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\SSR\Attribute\Component;
use Waaseyaa\SSR\ComponentMetadata;
use Waaseyaa\SSR\ComponentRegistry;
use Waaseyaa\SSR\ComponentRenderer;

/**
 * Cross-package integration tests: CLI + SSR + Entity system.
 *
 * Exercises interactions between waaseyaa/cli, waaseyaa/ssr, waaseyaa/entity,
 * waaseyaa/config, and waaseyaa/cache using in-memory services.
 */
#[CoversNothing]
final class CliSsrCrossPackageIntegrationTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $articleStorage;
    private InMemoryEntityStorage $userStorage;
    private ConfigManager $configManager;
    private MemoryStorage $activeStorage;
    private MemoryStorage $syncStorage;
    private CacheFactory $cacheFactory;
    private ComponentRegistry $registry;
    private ComponentRenderer $renderer;
    private Environment $twig;

    protected function setUp(): void
    {
        $fieldRegistry = new FieldDefinitionRegistry();
        $fieldRegistry->registerCoreFields('article', [
            'author' => ['type' => 'string', 'read' => FieldReadLevel::Public],
            'body' => ['type' => 'text', 'read' => FieldReadLevel::Public],
        ]);
        ContentEntityBase::setFieldRegistry($fieldRegistry);
        // Entity system.
        $this->articleStorage = new InMemoryEntityStorage('article');
        $this->userStorage = new InMemoryEntityStorage('user');

        $articleStorage = $this->articleStorage;
        $userStorage = $this->userStorage;

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            function ($definition) use ($articleStorage, $userStorage) {
                return match ($definition->id()) {
                    'article' => $articleStorage,
                    'user' => $userStorage,
                    default => throw new \RuntimeException("Unknown entity type: {$definition->id()}"),
                };
            },
            // C-22 WP3: create/save now go through the canonical repository.
            function (string $entityTypeId) use ($articleStorage, $userStorage) {
                return match ($entityTypeId) {
                    'article' => new InMemoryEntityRepository($articleStorage),
                    'user' => new InMemoryEntityRepository($userStorage),
                    default => throw new \RuntimeException("Unknown entity type: {$entityTypeId}"),
                };
            },
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \Waaseyaa\Api\Tests\Fixtures\ArticleContentTestEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
            ],
            _fieldDefinitions: [
                'id' => ['type' => 'integer', 'read' => FieldReadLevel::Public],
                'uuid' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'title' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'type' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'author' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'body' => ['type' => 'text', 'read' => FieldReadLevel::Public],
            ],
        ));

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: \Waaseyaa\Api\Tests\Fixtures\UserNameContentTestEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'name',
            ],
        ));

        // Config system.
        $this->activeStorage = new MemoryStorage();
        $this->syncStorage = new MemoryStorage();
        $this->configManager = new ConfigManager(
            $this->activeStorage,
            $this->syncStorage,
            new EventDispatcher(),
        );

        // Cache system.
        $this->cacheFactory = new CacheFactory();

        // SSR system.
        $this->registry = new ComponentRegistry();
        $this->twig = new Environment(new ArrayLoader([
            'article-card.html.twig' => '<article><h2>{{ title }}</h2><p>By {{ author }}</p><div>{{ body }}</div></article>',
        ]));
        $this->renderer = new ComponentRenderer($this->twig, $this->registry);

        $this->registry->register(new ComponentMetadata(
            name: 'article-card',
            template: 'article-card.html.twig',
            className: ArticleCardComponent::class,
        ));
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
    }

    #[Test]
    public function testEntityCreatedViaCLICanBeRenderedBySSR(): void
    {
        // Create an entity via EntityCreateHandler.
        $manager = $this->entityTypeManager;
        $container = new class ($manager) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly EntityTypeManager $manager) {}

            public function get(string $id): mixed
            {
                if ($id === EntityCreateHandler::class) {
                    return new EntityCreateHandler($this->manager);
                }

                throw new \RuntimeException("Container::get({$id}) unexpected");
            }

            public function has(string $id): bool
            {
                return $id === EntityCreateHandler::class;
            }
        };

        $provider = new EntityTypeServiceProvider();
        $createDefinition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'entity:create') {
                $createDefinition = $cmd;
                break;
            }
        }
        $this->assertNotNull($createDefinition);

        $tester = CliTester::for($createDefinition, $container);
        $tester->executeMap([
            'entity_type' => 'article',
            '--values' => json_encode([
                'title' => 'Integration Test Article',
                'type' => 'blog',
                'body' => 'This article was created via CLI.',
                'author' => 'admin',
            ]),
        ]);
        $this->assertSame(0, $tester->getExitCode());

        // Load entity from storage.
        $entity = $this->articleStorage->load(1);
        $this->assertNotNull($entity);

        // Use entity data as component props and render via SSR.
        $html = $this->renderer->render('article-card', [
            'title' => $entity->label(),
            'author' => $entity->get('author'),
            'body' => $entity->get('body'),
        ]);

        // Verify HTML contains entity data.
        $this->assertStringContainsString('<h2>Integration Test Article</h2>', $html);
        $this->assertStringContainsString('<p>By admin</p>', $html);
        $this->assertStringContainsString('This article was created via CLI.', $html);
    }

    #[Test]
    public function testConfigExportImportPreservesEntityTypes(): void
    {
        // Set up entity type definitions as config.
        $this->activeStorage->write('entity_type.article', [
            'id' => 'article',
            'label' => 'Article',
            'class' => TestEntity::class,
            'keys' => ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        ]);
        $this->activeStorage->write('entity_type.user', [
            'id' => 'user',
            'label' => 'User',
            'class' => TestEntity::class,
            'keys' => ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
        ]);

        // Export config via native handler.
        $provider = new ConfigCacheDbAuditServiceProvider();
        $exportDef = null;
        $importDef = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'config:export') {
                $exportDef = $cmd;
            }
            if ($cmd->name === 'config:import') {
                $importDef = $cmd;
            }
        }
        $this->assertNotNull($exportDef);
        $this->assertNotNull($importDef);

        $configManager = $this->configManager;
        $configContainer = $this->makeConfigContainer($configManager);

        $exportTester = CliTester::for($exportDef, $configContainer);
        $exportTester->execute([]);
        $this->assertSame(0, $exportTester->getExitCode());

        // Verify sync has the entity type configs.
        $this->assertNotFalse($this->syncStorage->read('entity_type.article'));
        $this->assertNotFalse($this->syncStorage->read('entity_type.user'));

        // Modify active storage (simulate a bad change).
        $this->activeStorage->write('entity_type.article', [
            'id' => 'article',
            'label' => 'Modified Article',
            'class' => TestEntity::class,
            'keys' => ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        ]);

        // Import config via native handler (should restore from sync).
        $importTester = CliTester::for($importDef, $configContainer);
        $importTester->execute([]);
        $this->assertSame(0, $importTester->getExitCode());

        // Verify entity type definitions are restored.
        $articleConfig = $this->activeStorage->read('entity_type.article');
        $this->assertIsArray($articleConfig);
        $this->assertSame('Article', $articleConfig['label']);

        $userConfig = $this->activeStorage->read('entity_type.user');
        $this->assertIsArray($userConfig);
        $this->assertSame('User', $userConfig['label']);
    }

    #[Test]
    public function testCacheClearDoesNotAffectEntityStorage(): void
    {
        // Populate entity storage.
        $entity1 = $this->articleStorage->create(['title' => 'Cached Article 1', 'type' => 'blog']);
        $this->articleStorage->save($entity1);
        $entity2 = $this->articleStorage->create(['title' => 'Cached Article 2', 'type' => 'news']);
        $this->articleStorage->save($entity2);

        // Also populate cache.
        $cache = $this->cacheFactory->get('default');
        $cache->set('article:1', 'cached_data_1');
        $cache->set('article:2', 'cached_data_2');

        // Verify both storage and cache are populated.
        $this->assertNotNull($this->articleStorage->load(1));
        $this->assertNotNull($this->articleStorage->load(2));
        $this->assertNotFalse($cache->get('article:1'));
        $this->assertNotFalse($cache->get('article:2'));

        // Clear cache via native handler.
        $provider = new ConfigCacheDbAuditServiceProvider();
        $cacheClearDef = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'cache:clear') {
                $cacheClearDef = $cmd;
                break;
            }
        }
        $this->assertNotNull($cacheClearDef);

        $cacheFactory = $this->cacheFactory;
        $cacheContainer = new class ($cacheFactory) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly \Waaseyaa\Cache\CacheFactoryInterface $f) {}
            public function get(string $id): mixed
            {
                if ($id === CacheClearHandler::class) {
                    return new CacheClearHandler($this->f);
                }
                throw new \RuntimeException("Container::get({$id}) unexpected");
            }
            public function has(string $id): bool
            {
                return $id === CacheClearHandler::class;
            }
        };

        $tester = CliTester::for($cacheClearDef, $cacheContainer);
        $tester->execute([]);
        $this->assertSame(0, $tester->getExitCode());

        // Verify cache is cleared.
        $this->assertFalse($cache->get('article:1'));
        $this->assertFalse($cache->get('article:2'));

        // Verify entities still exist in storage.
        $article1 = $this->articleStorage->load(1);
        $this->assertNotNull($article1);
        $this->assertSame('Cached Article 1', $article1->label());

        $article2 = $this->articleStorage->load(2);
        $this->assertNotNull($article2);
        $this->assertSame('Cached Article 2', $article2->label());
    }
    private function makeConfigContainer(\Waaseyaa\Config\ConfigManagerInterface $manager): \Psr\Container\ContainerInterface
    {
        return new class ($manager) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly \Waaseyaa\Config\ConfigManagerInterface $m) {}
            public function get(string $id): mixed
            {
                return match ($id) {
                    ConfigExportHandler::class => new ConfigExportHandler($this->m),
                    ConfigImportHandler::class => new ConfigImportHandler($this->m),
                    default => throw new \RuntimeException("Container::get({$id}) unexpected"),
                };
            }
            public function has(string $id): bool
            {
                return in_array($id, [ConfigExportHandler::class, ConfigImportHandler::class], true);
            }
        };
    }
}

// Test component class for cross-package tests.

#[Component(name: 'article-card', template: 'article-card.html.twig')]
class ArticleCardComponent
{
    public string $title = '';
    public string $author = '';
    public string $body = '';
}
