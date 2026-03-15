<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase4;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Config\Config;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigManager;
use Waaseyaa\Config\Event\ConfigEvent;
use Waaseyaa\Config\Event\ConfigEvents;
use Waaseyaa\Config\Storage\FileStorage;
use Waaseyaa\Config\Storage\MemoryStorage;

/**
 * Config system integration tests.
 *
 * Exercises: waaseyaa/config with FileStorage (YAML round-trip), MemoryStorage,
 * ConfigFactory, ConfigManager, and event dispatching.
 */
final class ConfigEntityLifecycleTest extends TestCase
{
    private string $tempDir;
    private string $activeDirPath;
    private string $syncDirPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_test_' . uniqid();
        $this->activeDirPath = $this->tempDir . '/active';
        $this->syncDirPath = $this->tempDir . '/sync';
        mkdir($this->activeDirPath, 0777, true);
        mkdir($this->syncDirPath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
    }

    // ---- FileStorage YAML round-trip ----

    public function testFileStorageWriteAndReadYamlRoundTrip(): void
    {
        $storage = new FileStorage($this->activeDirPath);

        $data = [
            'name' => 'site_name',
            'settings' => [
                'page_front' => '/home',
                'admin_theme' => 'seven',
                'items_per_page' => 10,
            ],
            'features' => ['search', 'caching', 'api'],
        ];

        $result = $storage->write('system.site', $data);
        $this->assertTrue($result, 'Write should return true on success');

        // Verify the file exists on disk.
        $this->assertTrue(
            file_exists($this->activeDirPath . '/system.site.yml'),
            'YAML file should exist on disk',
        );

        // Read back and verify data round-trip.
        $loaded = $storage->read('system.site');
        $this->assertIsArray($loaded);
        $this->assertSame($data, $loaded);
    }

    public function testFileStorageReadNonexistent(): void
    {
        $storage = new FileStorage($this->activeDirPath);
        $this->assertFalse($storage->read('nonexistent.config'));
    }

    public function testFileStorageListAll(): void
    {
        $storage = new FileStorage($this->activeDirPath);
        $storage->write('system.site', ['name' => 'My Site']);
        $storage->write('system.performance', ['cache' => true]);
        $storage->write('node.type.article', ['label' => 'Article']);

        $all = $storage->listAll();
        $this->assertCount(3, $all);
        $this->assertContains('system.site', $all);
        $this->assertContains('system.performance', $all);
        $this->assertContains('node.type.article', $all);

        // Test prefix filtering.
        $systemConfigs = $storage->listAll('system.');
        $this->assertCount(2, $systemConfigs);
        $this->assertContains('system.site', $systemConfigs);
        $this->assertContains('system.performance', $systemConfigs);
    }

    public function testFileStorageDeleteRemovesFile(): void
    {
        $storage = new FileStorage($this->activeDirPath);
        $storage->write('system.site', ['name' => 'My Site']);

        $this->assertTrue($storage->exists('system.site'));

        $storage->delete('system.site');

        $this->assertFalse($storage->exists('system.site'));
        $this->assertFalse(file_exists($this->activeDirPath . '/system.site.yml'));
    }

    public function testFileStorageRename(): void
    {
        $storage = new FileStorage($this->activeDirPath);
        $storage->write('old.name', ['key' => 'value']);

        $storage->rename('old.name', 'new.name');

        $this->assertFalse($storage->exists('old.name'));
        $this->assertTrue($storage->exists('new.name'));
        $this->assertSame(['key' => 'value'], $storage->read('new.name'));
    }

    // ---- ConfigFactory with FileStorage ----

    public function testConfigFactorySaveAndLoadWithFileStorage(): void
    {
        $eventDispatcher = new EventDispatcher();
        $storage = new FileStorage($this->activeDirPath);
        $factory = new ConfigFactory($storage, $eventDispatcher);

        // Save via editable config.
        $config = $factory->getEditable('system.site');
        $this->assertTrue($config->isNew(), 'Config should be new initially');

        $config->set('name', 'Waaseyaa');
        $config->set('page.front', '/home');
        $config->set('theme.default', 'olivero');
        $config->save();

        // Load via immutable config (should read from file).
        $loaded = $factory->get('system.site');
        $this->assertSame('Waaseyaa', $loaded->get('name'));
        $this->assertSame('/home', $loaded->get('page.front'));
        $this->assertSame('olivero', $loaded->get('theme.default'));
        $this->assertFalse($loaded->isNew());
    }

    public function testConfigFactoryNestedDataRoundTrip(): void
    {
        $eventDispatcher = new EventDispatcher();
        $storage = new FileStorage($this->activeDirPath);
        $factory = new ConfigFactory($storage, $eventDispatcher);

        $config = $factory->getEditable('deeply.nested');
        $config->set('level1.level2.level3.value', 42);
        $config->set('level1.level2.sibling', 'hello');
        $config->save();

        // Reload entirely fresh factory to prove it comes from file.
        $freshFactory = new ConfigFactory(
            new FileStorage($this->activeDirPath),
            new EventDispatcher(),
        );
        $loaded = $freshFactory->get('deeply.nested');
        $this->assertSame(42, $loaded->get('level1.level2.level3.value'));
        $this->assertSame('hello', $loaded->get('level1.level2.sibling'));
    }

    public function testConfigFactoryLoadMultiple(): void
    {
        $eventDispatcher = new EventDispatcher();
        $storage = new FileStorage($this->activeDirPath);
        $factory = new ConfigFactory($storage, $eventDispatcher);

        $factory->getEditable('config.one')->set('key', 'one')->save();
        $factory->getEditable('config.two')->set('key', 'two')->save();

        $configs = $factory->loadMultiple(['config.one', 'config.two']);
        $this->assertCount(2, $configs);
        $this->assertSame('one', $configs['config.one']->get('key'));
        $this->assertSame('two', $configs['config.two']->get('key'));
    }

    // ---- Config Events ----

    public function testConfigEventsAreDispatched(): void
    {
        $eventDispatcher = new EventDispatcher();
        $storage = new MemoryStorage();
        $factory = new ConfigFactory($storage, $eventDispatcher);
        $firedEvents = [];

        $eventDispatcher->addListener(
            ConfigEvents::PRE_SAVE->value,
            function (ConfigEvent $event) use (&$firedEvents) {
                $firedEvents[] = 'pre_save:' . $event->getConfigName();
            },
        );

        $eventDispatcher->addListener(
            ConfigEvents::POST_SAVE->value,
            function (ConfigEvent $event) use (&$firedEvents) {
                $firedEvents[] = 'post_save:' . $event->getConfigName();
            },
        );

        $config = $factory->getEditable('test.config');
        $config->set('key', 'value');
        $config->save();

        $this->assertSame(
            ['pre_save:test.config', 'post_save:test.config'],
            $firedEvents,
        );
    }

    public function testConfigDeleteEventsAreDispatched(): void
    {
        $eventDispatcher = new EventDispatcher();
        $storage = new MemoryStorage();
        $factory = new ConfigFactory($storage, $eventDispatcher);
        $firedEvents = [];

        // Create config first.
        $factory->getEditable('test.deleteme')->set('key', 'val')->save();

        $eventDispatcher->addListener(
            ConfigEvents::PRE_DELETE->value,
            function (ConfigEvent $event) use (&$firedEvents) {
                $firedEvents[] = 'pre_delete:' . $event->getConfigName();
            },
        );

        $eventDispatcher->addListener(
            ConfigEvents::POST_DELETE->value,
            function (ConfigEvent $event) use (&$firedEvents) {
                $firedEvents[] = 'post_delete:' . $event->getConfigName();
            },
        );

        $config = $factory->getEditable('test.deleteme');
        $config->delete();

        $this->assertSame(
            ['pre_delete:test.deleteme', 'post_delete:test.deleteme'],
            $firedEvents,
        );
    }

    public function testPreSaveEventCanModifyData(): void
    {
        $eventDispatcher = new EventDispatcher();
        $storage = new MemoryStorage();
        $factory = new ConfigFactory($storage, $eventDispatcher);

        // Listen to PRE_SAVE and modify the data.
        $eventDispatcher->addListener(
            ConfigEvents::PRE_SAVE->value,
            function (ConfigEvent $event) {
                $data = $event->getData();
                $data['injected'] = 'by_event';
                $event->setData($data);
            },
        );

        $config = $factory->getEditable('test.inject');
        $config->set('original', 'value');
        $config->save();

        // The event-aware storage should have saved the modified data.
        $loaded = $storage->read('test.inject');
        $this->assertIsArray($loaded);
        $this->assertSame('by_event', $loaded['injected']);
    }

    // ---- ConfigManager import/export ----

    public function testConfigManagerExportCopiesActiveToSync(): void
    {
        $eventDispatcher = new EventDispatcher();
        $activeStorage = new FileStorage($this->activeDirPath);
        $syncStorage = new FileStorage($this->syncDirPath);

        // Write to active.
        $activeStorage->write('system.site', ['name' => 'My Site']);
        $activeStorage->write('system.performance', ['cache' => true]);

        $manager = new ConfigManager($activeStorage, $syncStorage, $eventDispatcher);
        $manager->export();

        // Sync should now have the same configs.
        $this->assertSame(['name' => 'My Site'], $syncStorage->read('system.site'));
        $this->assertSame(['cache' => true], $syncStorage->read('system.performance'));
    }

    public function testConfigManagerImportCreatesNewConfigs(): void
    {
        $eventDispatcher = new EventDispatcher();
        $activeStorage = new FileStorage($this->activeDirPath);
        $syncStorage = new FileStorage($this->syncDirPath);

        // Write to sync (simulating an import scenario).
        $syncStorage->write('new.config', ['key' => 'new_value']);

        $manager = new ConfigManager($activeStorage, $syncStorage, $eventDispatcher);
        $result = $manager->import();

        $this->assertFalse($result->hasErrors());
        $this->assertContains('new.config', $result->created);
        $this->assertEmpty($result->updated);
        $this->assertEmpty($result->deleted);

        // Verify it is now in active.
        $this->assertSame(['key' => 'new_value'], $activeStorage->read('new.config'));
    }

    public function testConfigManagerImportUpdatesExistingConfigs(): void
    {
        $eventDispatcher = new EventDispatcher();
        $activeStorage = new FileStorage($this->activeDirPath);
        $syncStorage = new FileStorage($this->syncDirPath);

        // Same config in both, but different values.
        $activeStorage->write('system.site', ['name' => 'Old Name']);
        $syncStorage->write('system.site', ['name' => 'New Name']);

        $manager = new ConfigManager($activeStorage, $syncStorage, $eventDispatcher);
        $result = $manager->import();

        $this->assertFalse($result->hasErrors());
        $this->assertContains('system.site', $result->updated);
        $this->assertSame(['name' => 'New Name'], $activeStorage->read('system.site'));
    }

    public function testConfigManagerImportDeletesRemovedConfigs(): void
    {
        $eventDispatcher = new EventDispatcher();
        $activeStorage = new FileStorage($this->activeDirPath);
        $syncStorage = new FileStorage($this->syncDirPath);

        // Active has a config that sync does not.
        $activeStorage->write('system.site', ['name' => 'Site']);
        $activeStorage->write('obsolete.config', ['old' => true]);
        $syncStorage->write('system.site', ['name' => 'Site']);

        $manager = new ConfigManager($activeStorage, $syncStorage, $eventDispatcher);
        $result = $manager->import();

        $this->assertFalse($result->hasErrors());
        $this->assertContains('obsolete.config', $result->deleted);
        $this->assertFalse($activeStorage->exists('obsolete.config'));
    }

    public function testConfigManagerImportDispatchesEvent(): void
    {
        $eventDispatcher = new EventDispatcher();
        $firedEvents = [];

        $eventDispatcher->addListener(
            ConfigEvents::IMPORT->value,
            function (ConfigEvent $event) use (&$firedEvents) {
                $firedEvents[] = 'import';
            },
        );

        $activeStorage = new MemoryStorage();
        $syncStorage = new MemoryStorage();
        $syncStorage->write('new.config', ['key' => 'val']);

        $manager = new ConfigManager($activeStorage, $syncStorage, $eventDispatcher);
        $manager->import();

        $this->assertSame(['import'], $firedEvents);
    }

    public function testConfigManagerDiff(): void
    {
        $eventDispatcher = new EventDispatcher();
        $activeStorage = new MemoryStorage();
        $syncStorage = new MemoryStorage();

        $activeStorage->write('test.config', ['active_key' => 'active_val']);
        $syncStorage->write('test.config', ['sync_key' => 'sync_val']);

        $manager = new ConfigManager($activeStorage, $syncStorage, $eventDispatcher);
        $diff = $manager->diff('test.config');

        $this->assertTrue($diff['has_changes']);
        $this->assertSame(['active_key' => 'active_val'], $diff['active']);
        $this->assertSame(['sync_key' => 'sync_val'], $diff['sync']);
    }

    public function testConfigManagerExportAndImportRoundTrip(): void
    {
        $eventDispatcher = new EventDispatcher();
        $activeStorage = new FileStorage($this->activeDirPath);
        $syncStorage = new FileStorage($this->syncDirPath);

        // Write configs to active.
        $activeStorage->write('system.site', ['name' => 'Waaseyaa', 'slogan' => 'Modern CMS']);
        $activeStorage->write('node.type.article', ['label' => 'Article', 'description' => 'Blog posts']);
        $activeStorage->write('node.type.page', ['label' => 'Page', 'description' => 'Static pages']);

        // Export to sync.
        $manager = new ConfigManager($activeStorage, $syncStorage, $eventDispatcher);
        $manager->export();

        // Modify active storage (simulating site changes).
        $activeStorage->write('system.site', ['name' => 'Waaseyaa Modified', 'slogan' => 'Changed']);
        $activeStorage->write('new.config', ['added' => true]);
        $activeStorage->delete('node.type.page');

        // Import from sync (should restore to original state).
        $result = $manager->import();
        $this->assertFalse($result->hasErrors());

        // Verify active is back to the exported state.
        $this->assertSame(
            ['name' => 'Waaseyaa', 'slogan' => 'Modern CMS'],
            $activeStorage->read('system.site'),
        );
        $this->assertSame(
            ['label' => 'Page', 'description' => 'Static pages'],
            $activeStorage->read('node.type.page'),
        );
        // 'new.config' should have been deleted by import (not in sync).
        $this->assertFalse($activeStorage->exists('new.config'));
    }

    // ---- ImmutableConfig ----

    public function testImmutableConfigThrowsOnSet(): void
    {
        $eventDispatcher = new EventDispatcher();
        $storage = new MemoryStorage();
        $factory = new ConfigFactory($storage, $eventDispatcher);

        $factory->getEditable('test.immutable')->set('key', 'value')->save();

        $immutable = $factory->get('test.immutable');

        $this->expectException(\Waaseyaa\Config\Exception\ImmutableConfigException::class);
        $immutable->set('key', 'new_value');
    }

    /**
     * Recursively deletes a directory.
     */
    private function recursiveDelete(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            unlink($path);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
