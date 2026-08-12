<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit;

use Waaseyaa\Config\Config;
use Waaseyaa\Config\Exception\ImmutableConfigException;
use Waaseyaa\Config\Exception\ConfigMutationFailedException;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Config\StorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
#[CoversClass(ImmutableConfigException::class)]
final class ConfigTest extends TestCase
{
    private MemoryStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new MemoryStorage();
    }

    public function testGetNameReturnsConfigName(): void
    {
        $config = $this->createConfig('system.site');

        $this->assertSame('system.site', $config->getName());
    }

    public function testGetWithEmptyKeyReturnsAllData(): void
    {
        $data = ['name' => 'My Site', 'slogan' => 'Testing'];
        $config = $this->createConfig('system.site', $data);

        $this->assertSame($data, $config->get());
        $this->assertSame($data, $config->get(''));
    }

    public function testGetWithSimpleKey(): void
    {
        $config = $this->createConfig('system.site', [
            'name' => 'My Site',
            'slogan' => 'Testing',
        ]);

        $this->assertSame('My Site', $config->get('name'));
        $this->assertSame('Testing', $config->get('slogan'));
    }

    public function testGetWithDotNotation(): void
    {
        $config = $this->createConfig('system.site', [
            'page' => [
                'front' => '/home',
                'meta' => [
                    'title' => 'Welcome',
                ],
            ],
        ]);

        $this->assertSame('/home', $config->get('page.front'));
        $this->assertSame('Welcome', $config->get('page.meta.title'));
        $this->assertSame(['title' => 'Welcome'], $config->get('page.meta'));
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        $config = $this->createConfig('test', ['existing' => 'value']);

        $this->assertNull($config->get('missing'));
        $this->assertNull($config->get('deep.missing.key'));
    }

    public function testSetWithSimpleKey(): void
    {
        $config = $this->createConfig('test');

        $result = $config->set('name', 'Test');

        $this->assertSame($config, $result);
        $this->assertSame('Test', $config->get('name'));
    }

    public function testSetWithDotNotation(): void
    {
        $config = $this->createConfig('test');

        $config->set('page.front', '/home');

        $this->assertSame('/home', $config->get('page.front'));
        $this->assertSame(['front' => '/home'], $config->get('page'));
    }

    public function testSetCreatesIntermediateArrays(): void
    {
        $config = $this->createConfig('test');

        $config->set('deep.nested.value', 42);

        $this->assertSame(42, $config->get('deep.nested.value'));
        $this->assertSame(['value' => 42], $config->get('deep.nested'));
    }

    public function testSetOverwritesExistingValue(): void
    {
        $config = $this->createConfig('test', ['name' => 'Old']);

        $config->set('name', 'New');

        $this->assertSame('New', $config->get('name'));
    }

    public function testClearRemovesKey(): void
    {
        $config = $this->createConfig('test', [
            'name' => 'Test',
            'slogan' => 'Hello',
        ]);

        $result = $config->clear('name');

        $this->assertSame($config, $result);
        $this->assertNull($config->get('name'));
        $this->assertSame('Hello', $config->get('slogan'));
    }

    public function testClearWithDotNotation(): void
    {
        $config = $this->createConfig('test', [
            'page' => [
                'front' => '/home',
                'error' => '/404',
            ],
        ]);

        $config->clear('page.front');

        $this->assertNull($config->get('page.front'));
        $this->assertSame('/404', $config->get('page.error'));
    }

    public function testClearNonExistingKeyDoesNothing(): void
    {
        $config = $this->createConfig('test', ['name' => 'Test']);

        $config->clear('nonexistent');
        $config->clear('deep.missing.key');

        $this->assertSame('Test', $config->get('name'));
    }

    public function testSaveWritesToStorage(): void
    {
        $config = $this->createConfig('system.site');
        $config->set('name', 'My Site');
        $config->save();

        $this->assertSame(['name' => 'My Site'], $this->storage->read('system.site'));
    }

    public function testSaveMarksConfigAsNotNew(): void
    {
        $config = $this->createConfig('system.site');
        $this->assertTrue($config->isNew());

        $config->set('name', 'Test');
        $config->save();

        $this->assertFalse($config->isNew());
    }

    public function testDelete(): void
    {
        $this->storage->write('test', ['value' => 1]);
        $config = $this->createConfig('test', ['value' => 1], isNew: false);

        $result = $config->delete();

        $this->assertSame($config, $result);
        $this->assertFalse($this->storage->exists('test'));
        $this->assertTrue($config->isNew());
        $this->assertSame([], $config->getRawData());
    }

    public function testIsNewTrueForEmptyConfig(): void
    {
        $config = $this->createConfig('new.config');

        $this->assertTrue($config->isNew());
    }

    public function testIsNewFalseForConfigWithData(): void
    {
        $config = $this->createConfig('existing', ['key' => 'value'], isNew: false);

        $this->assertFalse($config->isNew());
    }

    public function testGetRawDataReturnsEntireArray(): void
    {
        $data = ['name' => 'Site', 'settings' => ['key' => 'val']];
        $config = $this->createConfig('test', $data);

        $this->assertSame($data, $config->getRawData());
    }

    public function testImmutableConfigThrowsOnSet(): void
    {
        $config = $this->createConfig('test', ['name' => 'value'], immutable: true);

        $this->expectException(ImmutableConfigException::class);
        $this->expectExceptionMessage('Config "test" is immutable');

        $config->set('name', 'new');
    }

    public function testImmutableConfigThrowsOnClear(): void
    {
        $config = $this->createConfig('test', ['name' => 'value'], immutable: true);

        $this->expectException(ImmutableConfigException::class);

        $config->clear('name');
    }

    public function testImmutableConfigThrowsOnDelete(): void
    {
        $config = $this->createConfig('test', ['name' => 'value'], immutable: true);

        $this->expectException(ImmutableConfigException::class);

        $config->delete();
    }

    public function testImmutableConfigThrowsOnSave(): void
    {
        $config = $this->createConfig('test', ['name' => 'value'], immutable: true);

        $this->expectException(ImmutableConfigException::class);

        $config->save();
    }

    public function testImmutableConfigAllowsGet(): void
    {
        $config = $this->createConfig('test', ['name' => 'value'], immutable: true);

        $this->assertSame('value', $config->get('name'));
        $this->assertSame(['name' => 'value'], $config->getRawData());
        $this->assertSame('test', $config->getName());
    }

    public function testIsImmutableReflectsState(): void
    {
        $mutable = $this->createConfig('test');
        $immutable = $this->createConfig('test', immutable: true);

        $this->assertFalse($mutable->isImmutable());
        $this->assertTrue($immutable->isImmutable());
    }

    public function testSaveReturnsSelf(): void
    {
        $config = $this->createConfig('test');
        $config->set('key', 'value');

        $result = $config->save();

        $this->assertSame($config, $result);
    }

    public function testFalseSaveDoesNotMarkTheConfigPersisted(): void
    {
        $storage = $this->refusingStorage();
        $config = new Config('system.site', $storage, ['name' => 'Changed'], isNew: true);

        try {
            $config->save();
            self::fail('False storage write was reported as success.');
        } catch (ConfigMutationFailedException $exception) {
            self::assertStringContainsString('save failed', $exception->getMessage());
        }
        self::assertTrue($config->isNew());
        self::assertSame(['name' => 'Changed'], $config->getRawData());
    }

    public function testFalseDeletePreservesTheLoadedConfigState(): void
    {
        $config = new Config('system.site', $this->refusingStorage(), ['name' => 'Known good'], isNew: false);

        try {
            $config->delete();
            self::fail('False storage delete was reported as success.');
        } catch (ConfigMutationFailedException $exception) {
            self::assertStringContainsString('delete failed', $exception->getMessage());
        }
        self::assertFalse($config->isNew());
        self::assertSame(['name' => 'Known good'], $config->getRawData());
    }

    private function refusingStorage(): StorageInterface
    {
        return new class implements StorageInterface {
            public function exists(string $name): bool { return true; }
            public function read(string $name): array|false { return ['name' => 'Known good']; }
            public function readMultiple(array $names): array { return []; }
            public function write(string $name, array $data): bool { return false; }
            public function delete(string $name): bool { return false; }
            public function rename(string $name, string $newName): bool { return false; }
            public function listAll(string $prefix = ''): array { return []; }
            public function deleteAll(string $prefix = ''): bool { return false; }
            public function createCollection(string $collection): static { return $this; }
            public function getCollectionName(): string { return ''; }
            public function getAllCollectionNames(): array { return []; }
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createConfig(
        string $name,
        array $data = [],
        bool $immutable = false,
        ?bool $isNew = null,
    ): Config {
        return new Config(
            name: $name,
            storage: $this->storage,
            data: $data,
            immutable: $immutable,
            isNew: $isNew,
        );
    }
}
