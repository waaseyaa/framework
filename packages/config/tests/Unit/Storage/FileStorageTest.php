<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Storage;

use Waaseyaa\Config\Storage\FileStorage;
use Waaseyaa\Config\StorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileStorage::class)]
final class FileStorageTest extends TestCase
{
    private string $directory;
    private FileStorage $storage;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/waaseyaa_config_test_' . uniqid();
        mkdir($this->directory, 0777, true);
        $this->storage = new FileStorage($this->directory);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testImplementsStorageInterface(): void
    {
        $this->assertInstanceOf(StorageInterface::class, $this->storage);
    }

    public function testExistsReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->storage->exists('nonexistent'));
    }

    public function testWriteAndRead(): void
    {
        $data = ['key' => 'value', 'nested' => ['foo' => 'bar']];

        $this->assertTrue($this->storage->write('test.config', $data));
        $this->assertTrue($this->storage->exists('test.config'));
        $this->assertSame($data, $this->storage->read('test.config'));
    }

    public function testWriteCreatesYamlFile(): void
    {
        $this->storage->write('system.site', ['name' => 'Test Site']);

        $filePath = $this->directory . '/system.site.yml';
        $this->assertFileExists($filePath);

        $content = file_get_contents($filePath);
        $this->assertStringContainsString('name: ', $content);
        $this->assertStringContainsString('Test Site', $content);
    }

    public function testReadReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->storage->read('nonexistent'));
    }

    public function testReadMultiple(): void
    {
        $this->storage->write('config.a', ['a' => 1]);
        $this->storage->write('config.b', ['b' => 2]);

        $result = $this->storage->readMultiple(['config.a', 'config.b', 'config.missing']);

        $this->assertCount(2, $result);
        $this->assertSame(['a' => 1], $result['config.a']);
        $this->assertSame(['b' => 2], $result['config.b']);
    }

    public function testDeleteExisting(): void
    {
        $this->storage->write('test', ['value' => true]);

        $this->assertTrue($this->storage->delete('test'));
        $this->assertFalse($this->storage->exists('test'));
        $this->assertFileDoesNotExist($this->directory . '/test.yml');
    }

    public function testDeleteNonExisting(): void
    {
        $this->assertFalse($this->storage->delete('nonexistent'));
    }

    public function testRename(): void
    {
        $this->storage->write('old.name', ['data' => 'test']);

        $this->assertTrue($this->storage->rename('old.name', 'new.name'));
        $this->assertFalse($this->storage->exists('old.name'));
        $this->assertTrue($this->storage->exists('new.name'));
        $this->assertSame(['data' => 'test'], $this->storage->read('new.name'));
    }

    public function testRenameNonExisting(): void
    {
        $this->assertFalse($this->storage->rename('nonexistent', 'new'));
    }

    public function testListAll(): void
    {
        $this->storage->write('system.site', []);
        $this->storage->write('system.mail', []);
        $this->storage->write('views.view.frontpage', []);

        $all = $this->storage->listAll();
        $this->assertSame(['system.mail', 'system.site', 'views.view.frontpage'], $all);
    }

    public function testListAllWithPrefix(): void
    {
        $this->storage->write('system.site', []);
        $this->storage->write('system.mail', []);
        $this->storage->write('views.view.frontpage', []);

        $filtered = $this->storage->listAll('system.');
        $this->assertSame(['system.mail', 'system.site'], $filtered);
    }

    public function testListAllEmptyDirectory(): void
    {
        $this->assertSame([], $this->storage->listAll());
    }

    public function testListAllNonExistentDirectory(): void
    {
        $storage = new FileStorage('/nonexistent/path');
        $this->assertSame([], $storage->listAll());
    }

    public function testDeleteAll(): void
    {
        $this->storage->write('a', ['v' => 1]);
        $this->storage->write('b', ['v' => 2]);

        $this->assertTrue($this->storage->deleteAll());
        $this->assertSame([], $this->storage->listAll());
    }

    public function testDeleteAllWithPrefix(): void
    {
        $this->storage->write('system.site', []);
        $this->storage->write('system.mail', []);
        $this->storage->write('views.view.frontpage', []);

        $this->assertTrue($this->storage->deleteAll('system.'));

        $this->assertFalse($this->storage->exists('system.site'));
        $this->assertFalse($this->storage->exists('system.mail'));
        $this->assertTrue($this->storage->exists('views.view.frontpage'));
    }

    public function testCreateCollectionCreatesSubdirectory(): void
    {
        $collection = $this->storage->createCollection('language.fr');

        $this->assertSame('language.fr', $collection->getCollectionName());
        $this->assertInstanceOf(FileStorage::class, $collection);
    }

    public function testCollectionDataIsIsolated(): void
    {
        $collection = $this->storage->createCollection('language.fr');
        $collection->write('system.site', ['name' => 'Site FR']);

        $this->assertFalse($this->storage->exists('system.site'));
        $this->assertTrue($collection->exists('system.site'));
        $this->assertSame(['name' => 'Site FR'], $collection->read('system.site'));
    }

    public function testCollectionStoredInSubdirectory(): void
    {
        $collection = $this->storage->createCollection('language.fr');
        $collection->write('test', ['value' => 1]);

        $expectedPath = $this->directory . '/language.fr/test.yml';
        $this->assertFileExists($expectedPath);
    }

    public function testDefaultCollectionNameIsEmpty(): void
    {
        $this->assertSame('', $this->storage->getCollectionName());
    }

    public function testGetAllCollectionNamesReturnsSubdirectories(): void
    {
        $this->storage->createCollection('language.fr')->write('test', []);
        $this->storage->createCollection('language.de')->write('test', []);

        $collections = $this->storage->getAllCollectionNames();
        $this->assertSame(['language.de', 'language.fr'], $collections);
    }

    public function testReadEmptyYamlFile(): void
    {
        file_put_contents($this->directory . '/empty.yml', '');

        $data = $this->storage->read('empty');
        $this->assertSame([], $data);
    }

    public function testWriteCreatesDirectoryIfNotExists(): void
    {
        $newDir = $this->directory . '/sub/deep';
        $storage = new FileStorage($newDir);

        $storage->write('test', ['key' => 'value']);

        $this->assertDirectoryExists($newDir);
        $this->assertSame(['key' => 'value'], $storage->read('test'));
    }

    public function testWriteIsAtomicAndLeavesNoTempFileBehind(): void
    {
        $this->storage->write('atomic.config', ['name' => 'first']);

        $filePath = $this->directory . '/atomic.config.yml';
        $tempPath = $filePath . '.tmp';

        $this->assertFileExists($filePath);
        $this->assertFileDoesNotExist($tempPath);
        $this->assertSame(['name' => 'first'], $this->storage->read('atomic.config'));
    }

    public function testWriteNeverExposesATruncatedFileAtTheTargetPath(): void
    {
        // Establish an original file, then overwrite it with new content.
        // Because write() lands in a sibling .tmp file and only exposes it
        // via rename(), the target path is either the old complete content
        // or the new complete content — never a partial write in between.
        $this->storage->write('atomic.config', ['name' => 'original', 'body' => str_repeat('x', 5000)]);
        $originalPath = $this->directory . '/atomic.config.yml';
        $originalContent = file_get_contents($originalPath);

        $this->assertTrue($this->storage->write('atomic.config', ['name' => 'updated', 'body' => str_repeat('y', 5000)]));

        $newContent = file_get_contents($originalPath);
        $this->assertNotSame($originalContent, $newContent);
        $this->assertStringContainsString('updated', $newContent);
        $this->assertFileDoesNotExist($originalPath . '.tmp');
    }

    public function testWriteReturnsFalseAndCleansUpTempFileWhenRenameFails(): void
    {
        // Force rename() to fail by making the target a directory instead of
        // a regular file — rename() from a file onto an existing directory
        // fails on POSIX. The original directory-as-"file" is left in place
        // (nothing was overwritten), and no stray temp file remains.
        $conflictPath = $this->directory . '/conflict.yml';
        mkdir($conflictPath);

        $this->assertFalse($this->storage->write('conflict', ['name' => 'value']));
        $this->assertDirectoryExists($conflictPath);
        $this->assertFileDoesNotExist($conflictPath . '.tmp');
    }

    public function testEnsureDirectoryCreatesWithGroupPermissionsNotWorldWritable(): void
    {
        $newDir = $this->directory . '/perm-check';
        $storage = new FileStorage($newDir);

        $previousUmask = umask(0);
        try {
            $storage->write('test', ['key' => 'value']);
        } finally {
            umask($previousUmask);
        }

        $this->assertDirectoryExists($newDir);
        $this->assertSame(0o775, fileperms($newDir) & 0o777);
    }

    public function testNestedDataPreservesStructure(): void
    {
        $data = [
            'level1' => [
                'level2' => [
                    'level3' => 'deep_value',
                ],
            ],
            'flat' => 'value',
        ];

        $this->storage->write('nested', $data);
        $this->assertSame($data, $this->storage->read('nested'));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
            } else {
                unlink($fileInfo->getPathname());
            }
        }

        rmdir($dir);
    }
}
