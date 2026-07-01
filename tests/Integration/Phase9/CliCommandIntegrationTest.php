<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase9;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\CacheClearHandler;
use Waaseyaa\CLI\Handler\ConfigExportHandler;
use Waaseyaa\CLI\Handler\ConfigImportHandler;
use Waaseyaa\CLI\Handler\EntityCreateHandler;
use Waaseyaa\CLI\Handler\EntityListHandler;
use Waaseyaa\CLI\Handler\InstallHandler;
use Waaseyaa\CLI\Handler\UserCreateHandler;
use Waaseyaa\CLI\Handler\UserRoleHandler;
use Waaseyaa\CLI\Provider\ConfigCacheDbAuditServiceProvider;
use Waaseyaa\CLI\Provider\EntityTypeServiceProvider;
use Waaseyaa\CLI\Provider\UserPermissionServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\ConfigManager;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Integration tests for CLI commands with real (in-memory) Waaseyaa services.
 *
 * Exercises: waaseyaa/cli commands with waaseyaa/cache (CacheFactory, MemoryBackend),
 * waaseyaa/config (ConfigManager, MemoryStorage), and waaseyaa/entity
 * (EntityTypeManager) using in-memory storage.
 */
#[CoversNothing]
final class CliCommandIntegrationTest extends TestCase
{
    private CacheFactory $cacheFactory;
    private ConfigManager $configManager;
    private MemoryStorage $activeStorage;
    private MemoryStorage $syncStorage;
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $articleStorage;
    private InMemoryEntityStorage $userStorage;

    protected function setUp(): void
    {
        // Cache factory with default MemoryBackend.
        $this->cacheFactory = new CacheFactory();

        // Config manager with two MemoryStorage instances (active + sync).
        $this->activeStorage = new MemoryStorage();
        $this->syncStorage = new MemoryStorage();
        $this->configManager = new ConfigManager(
            $this->activeStorage,
            $this->syncStorage,
            new EventDispatcher(),
        );

        // Entity type manager with in-memory storage for articles and users.
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
            // C-22: the query builder now lives on the repository.
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
    }

    #[Test]
    public function testCacheClearCommandClearsAllBins(): void
    {
        // Populate caches in multiple bins.
        $defaultBin = $this->cacheFactory->get('default');
        $renderBin = $this->cacheFactory->get('render');
        $discoveryBin = $this->cacheFactory->get('discovery');
        $configBin = $this->cacheFactory->get('config');

        $defaultBin->set('key1', 'value1');
        $renderBin->set('key2', 'value2');
        $discoveryBin->set('key3', 'value3');
        $configBin->set('key4', 'value4');

        // Verify caches are populated.
        $this->assertNotFalse($defaultBin->get('key1'));
        $this->assertNotFalse($renderBin->get('key2'));
        $this->assertNotFalse($discoveryBin->get('key3'));
        $this->assertNotFalse($configBin->get('key4'));

        // Run cache:clear command via native handler.
        $provider = new ConfigCacheDbAuditServiceProvider();
        $cacheClearDef = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'cache:clear') {
                $cacheClearDef = $cmd;
                break;
            }
        }
        $this->assertNotNull($cacheClearDef);

        $container = new class ($this->cacheFactory) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly \Waaseyaa\Cache\CacheFactoryInterface $factory) {}
            public function get(string $id): mixed
            {
                if ($id === CacheClearHandler::class) {
                    return new CacheClearHandler($this->factory);
                }
                throw new \RuntimeException("Container::get({$id}) unexpected");
            }
            public function has(string $id): bool
            {
                return $id === CacheClearHandler::class;
            }
        };

        $tester = CliTester::for($cacheClearDef, $container);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('All cache bins cleared', $tester->getStdout());

        // Verify all caches are empty.
        $this->assertFalse($defaultBin->get('key1'));
        $this->assertFalse($renderBin->get('key2'));
        $this->assertFalse($discoveryBin->get('key3'));
        $this->assertFalse($configBin->get('key4'));
    }

    #[Test]
    public function testConfigExportAndImport(): void
    {
        // Write config to active storage.
        $this->activeStorage->write('system.site', [
            'name' => 'My Waaseyaa Site',
            'slogan' => 'Built with Waaseyaa',
        ]);
        $this->activeStorage->write('system.theme', [
            'default' => 'stark',
        ]);

        // Export via native handler.
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
        $configContainer = new class ($configManager) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly \Waaseyaa\Config\ConfigManagerInterface $manager) {}
            public function get(string $id): mixed
            {
                return match ($id) {
                    ConfigExportHandler::class => new ConfigExportHandler($this->manager),
                    ConfigImportHandler::class => new ConfigImportHandler($this->manager),
                    default => throw new \RuntimeException("Container::get({$id}) unexpected"),
                };
            }
            public function has(string $id): bool
            {
                return in_array($id, [ConfigExportHandler::class, ConfigImportHandler::class], true);
            }
        };

        $exportTester = CliTester::for($exportDef, $configContainer);
        $exportTester->execute([]);

        $this->assertSame(0, $exportTester->getExitCode());
        $this->assertStringContainsString('Configuration exported. Active storage contains 2 items', $exportTester->getStdout());

        // Verify sync storage has the config.
        $this->assertSame(['name' => 'My Waaseyaa Site', 'slogan' => 'Built with Waaseyaa'], $this->syncStorage->read('system.site'));
        $this->assertSame(['default' => 'stark'], $this->syncStorage->read('system.theme'));

        // Modify active storage (simulate drift).
        $this->activeStorage->write('system.site', [
            'name' => 'Modified Site',
            'slogan' => 'Changed slogan',
        ]);

        // Verify active is now different.
        $activeData = $this->activeStorage->read('system.site');
        $this->assertSame('Modified Site', $activeData['name']);

        // Import via native handler (should restore from sync).
        $importTester = CliTester::for($importDef, $configContainer);
        $importTester->execute([]);

        $this->assertSame(0, $importTester->getExitCode());
        $this->assertStringContainsString('Configuration imported successfully', $importTester->getStdout());

        // Verify active matches sync after import.
        $restored = $this->activeStorage->read('system.site');
        $this->assertSame('My Waaseyaa Site', $restored['name']);
        $this->assertSame('Built with Waaseyaa', $restored['slogan']);
    }

    #[Test]
    public function testEntityCreateAndList(): void
    {
        $manager = $this->entityTypeManager;
        $container = new class ($manager) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly \Waaseyaa\Entity\EntityTypeManagerInterface $manager) {}

            public function get(string $id): mixed
            {
                return match ($id) {
                    EntityCreateHandler::class => new EntityCreateHandler($this->manager),
                    EntityListHandler::class   => new EntityListHandler($this->manager),
                    default => throw new \RuntimeException("Container::get({$id}) unexpected"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [EntityCreateHandler::class, EntityListHandler::class], true);
            }
        };

        $provider = new EntityTypeServiceProvider();
        $definitions = [];
        foreach ($provider->consoleCommands() as $cmd) {
            $definitions[$cmd->name] = $cmd;
        }

        // Create entities via entity:create handler.
        $tester1 = CliTester::for($definitions['entity:create'], $container);
        $tester1->executeMap([
            'entity_type' => 'article',
            '--values' => json_encode(['title' => 'First Article', 'type' => 'blog']),
        ]);
        $this->assertSame(0, $tester1->getExitCode());
        $this->assertStringContainsString('Created article entity with ID:', $tester1->getStdout());

        $tester2 = CliTester::for($definitions['entity:create'], $container);
        $tester2->executeMap([
            'entity_type' => 'article',
            '--values' => json_encode(['title' => 'Second Article', 'type' => 'news']),
        ]);
        $this->assertSame(0, $tester2->getExitCode());

        // List entities via entity:list handler.
        $listTester = CliTester::for($definitions['entity:list'], $container);
        $listTester->executeMap(['entity_type' => 'article']);

        $this->assertSame(0, $listTester->getExitCode());

        $output = $listTester->getStdout();
        $this->assertStringContainsString('First Article', $output);
        $this->assertStringContainsString('Second Article', $output);
    }

    #[Test]
    public function testUserCreateCommand(): void
    {
        $manager = $this->entityTypeManager;
        $container = new class ($manager) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly \Waaseyaa\Entity\EntityTypeManagerInterface $manager) {}

            public function get(string $id): mixed
            {
                if ($id === UserCreateHandler::class) {
                    return new UserCreateHandler($this->manager);
                }

                throw new \RuntimeException("Container::get({$id}) unexpected");
            }

            public function has(string $id): bool
            {
                return $id === UserCreateHandler::class;
            }
        };

        $provider = new UserPermissionServiceProvider();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'user:create') {
                $definition = $cmd;
                break;
            }
        }

        $this->assertNotNull($definition);

        $tester = CliTester::for($definition, $container);
        $tester->executeMap([
            'username' => 'testuser',
            '--email' => 'test@example.com',
        ]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Created user "testuser"', $tester->getStdout());

        // Verify entity was created in storage.
        $user = $this->userStorage->load(1);
        $this->assertNotNull($user);
        $this->assertSame('testuser', $user->get('name'));
        $this->assertSame('test@example.com', $user->get('mail'));
    }

    #[Test]
    public function testInstallCommand(): void
    {
        $handler = new InstallHandler(
            entityTypeManager: $this->entityTypeManager,
            configManager: $this->configManager,
        );
        $definition = new HandlerCommand(
            name: 'install',
            description: 'Install Waaseyaa with initial configuration',
            options: [
                new HandlerOption(name: 'site-name', mode: HandlerOptionMode::Required, description: 'The name of the site', default: 'Waaseyaa'),
                new HandlerOption(name: 'site-mail', mode: HandlerOptionMode::Required, description: 'Site email address', default: 'admin@example.com'),
                new HandlerOption(name: 'admin-email', mode: HandlerOptionMode::Required, description: 'Admin user email', default: 'admin@example.com'),
                new HandlerOption(name: 'admin-password', mode: HandlerOptionMode::Required, description: 'Admin user password'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Not used');
            }
            public function has(string $id): bool
            {
                return false;
            }
        };
        $tester = CliTester::for($definition, $container);
        $tester->executeMap(['--site-name' => 'Test Waaseyaa']);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Waaseyaa "Test Waaseyaa" installed successfully', $tester->getStdout());

        // Verify initial config was written.
        $siteConfig = $this->activeStorage->read('system.site');
        $this->assertIsArray($siteConfig);
        $this->assertSame('Test Waaseyaa', $siteConfig['name']);
        $this->assertSame('admin@example.com', $siteConfig['mail']);

        // Verify admin user was created.
        $admin = $this->userStorage->load(1);
        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->get('name'));
        $this->assertSame('admin@example.com', $admin->get('email'));
        $this->assertSame(['administrator'], $admin->get('roles'));
    }

    #[Test]
    public function testUserRoleAddAndRemove(): void
    {
        $manager = $this->entityTypeManager;
        $container = new class ($manager) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly \Waaseyaa\Entity\EntityTypeManagerInterface $manager) {}

            public function get(string $id): mixed
            {
                return match ($id) {
                    UserCreateHandler::class => new UserCreateHandler($this->manager),
                    UserRoleHandler::class   => new UserRoleHandler($this->manager),
                    default => throw new \RuntimeException("Container::get({$id}) unexpected"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [UserCreateHandler::class, UserRoleHandler::class], true);
            }
        };

        $provider = new UserPermissionServiceProvider();
        $createDefinition = null;
        $roleDefinition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'user:create') {
                $createDefinition = $cmd;
            } elseif ($cmd->name === 'user:role') {
                $roleDefinition = $cmd;
            }
        }

        $this->assertNotNull($createDefinition);
        $this->assertNotNull($roleDefinition);

        // First create a user.
        $createTester = CliTester::for($createDefinition, $container);
        $createTester->executeMap(['username' => 'editor']);
        $this->assertSame(0, $createTester->getExitCode());

        // Add a role.
        $addTester = CliTester::for($roleDefinition, $container);
        $addTester->executeMap([
            'user_id' => '1',
            'role' => 'editor',
        ]);
        $this->assertSame(0, $addTester->getExitCode());
        $this->assertStringContainsString('Added role "editor" to user 1', $addTester->getStdout());

        // Verify role is present.
        $user = $this->userStorage->load(1);
        $this->assertNotNull($user);
        $roles = $user->get('roles');
        $this->assertContains('editor', $roles);

        // Remove the role.
        $removeTester = CliTester::for($roleDefinition, $container);
        $removeTester->executeMap([
            'user_id' => '1',
            'role' => 'editor',
            '--remove' => true,
        ]);
        $this->assertSame(0, $removeTester->getExitCode());
        $this->assertStringContainsString('Removed role "editor" from user 1', $removeTester->getStdout());

        // Verify role is gone.
        $user = $this->userStorage->load(1);
        $this->assertNotNull($user);
        $roles = $user->get('roles');
        $this->assertNotContains('editor', $roles);
    }
}
