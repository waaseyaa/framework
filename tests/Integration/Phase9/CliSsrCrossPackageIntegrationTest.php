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
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\CLI\Command\Config\ConfigDiffCommand;
use Waaseyaa\CLI\Command\Config\ConfigExportCommand;
use Waaseyaa\CLI\Command\Config\ConfigImportCommand;
use Waaseyaa\CLI\Command\Config\ConfigManifestSignCommand;
use Waaseyaa\CLI\Command\Config\ConfigResetCommand;
use Waaseyaa\CLI\Command\Config\ConfigStatusCommand;
use Waaseyaa\CLI\Command\Config\ConfigValidateCommand;
use Waaseyaa\CLI\Handler\CacheClearHandler;
use Waaseyaa\CLI\Handler\EntityCreateHandler;
use Waaseyaa\CLI\Provider\ConfigCacheDbAuditServiceProvider;
use Waaseyaa\CLI\Provider\EntityTypeServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
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
    public function testConfigCommandsUseModernAuthorityAdapters(): void
    {
        $provider = new ConfigCacheDbAuditServiceProvider();
        $commands = [];
        foreach ($provider->consoleCommands() as $cmd) {
            $name = $cmd->getName();
            if (is_string($name) && str_starts_with($name, 'config:')) {
                $commands[$name] = $cmd->sourceClass();
            }
        }
        $this->assertSame([
            'config:export' => ConfigExportCommand::class,
            'config:manifest:sign' => ConfigManifestSignCommand::class,
            'config:import' => ConfigImportCommand::class,
            'config:diff' => ConfigDiffCommand::class,
            'config:status' => ConfigStatusCommand::class,
            'config:validate' => ConfigValidateCommand::class,
            'config:reset' => ConfigResetCommand::class,
        ], $commands);
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
}

// Test component class for cross-package tests.

#[Component(name: 'article-card', template: 'article-card.html.twig')]
class ArticleCardComponent
{
    public string $title = '';
    public string $author = '';
    public string $body = '';
}
