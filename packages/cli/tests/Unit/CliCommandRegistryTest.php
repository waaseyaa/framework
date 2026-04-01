<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\PermissionHandler;
use Waaseyaa\CLI\CliCommandRegistry;
use Waaseyaa\Cache\CacheConfiguration;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\Config\ConfigManager;
use Waaseyaa\Config\Storage\FileStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeIdNormalizer;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Audit\EntityAuditLogger;
use Waaseyaa\Foundation\Diagnostic\BootDiagnosticReport;
use Waaseyaa\Foundation\Diagnostic\HealthChecker;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\Schema\DefaultsSchemaRegistry;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(CliCommandRegistry::class)]
final class CliCommandRegistryTest extends TestCase
{
    #[Test]
    public function core_commands_are_owned_by_the_cli_registry(): void
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_cli_registry_' . uniqid();
        mkdir($projectRoot . '/config/active', 0755, true);
        mkdir($projectRoot . '/config/sync', 0755, true);
        mkdir($projectRoot . '/defaults', 0755, true);
        mkdir($projectRoot . '/storage', 0755, true);

        try {
            $dispatcher = new EventDispatcher();
            $entityTypeManager = new EntityTypeManager($dispatcher);
            $entityTypeManager->registerEntityType(new EntityType(
                id: 'node',
                label: 'Node',
                class: \stdClass::class,
                keys: ['id' => 'id'],
            ));

            $database = DBALDatabase::createSqlite();
            $activeStorage = new FileStorage($projectRoot . '/config/active');
            $syncStorage = new FileStorage($projectRoot . '/config/sync');
            $configManager = new ConfigManager($activeStorage, $syncStorage, $dispatcher);

            assert($database instanceof DBALDatabase);
            $pdo = $database->getConnection()->getNativeConnection();
            assert($pdo instanceof \PDO);

            $cacheFactory = new CacheFactory(new CacheConfiguration());
            $router = new WaaseyaaRouter();
            $registry = new CliCommandRegistry();
            $commands = $registry->coreCommands(
                projectRoot: $projectRoot,
                config: [],
                manifest: new PackageManifest(),
                dispatcher: $dispatcher,
                entityTypeManager: $entityTypeManager,
                lifecycleManager: new EntityTypeLifecycleManager($projectRoot),
                entityAuditLogger: new EntityAuditLogger($projectRoot),
                database: $database,
                configManager: $configManager,
                cacheFactory: $cacheFactory,
                router: $router,
                permissionHandler: new PermissionHandler(),
                manifestCompiler: new PackageManifestCompiler($projectRoot, $projectRoot . '/storage'),
                schemaRegistry: new DefaultsSchemaRegistry($projectRoot . '/defaults'),
                healthChecker: new HealthChecker(
                    bootReport: new BootDiagnosticReport(
                        registeredTypes: ['node' => true],
                        disabledTypeIds: [],
                        schemaCompatibility: [],
                    ),
                    database: $database,
                    entityTypeManager: $entityTypeManager,
                    projectRoot: $projectRoot,
                ),
                typeIdNormalizer: new EntityTypeIdNormalizer($entityTypeManager),
                semanticWarmer: null,
                pdo: $pdo,
            );

            $names = array_map(static fn($command): string => $command->getName() ?? '', $commands);

            $this->assertContains('about', $names);
            $this->assertContains('route:list', $names);
            $this->assertContains('optimize:manifest', $names);
            $this->assertContains('waaseyaa:version', $names);
        } finally {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($projectRoot);
        }
    }
}
