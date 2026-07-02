<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Media\File;
use Waaseyaa\Media\FileRepositoryInterface;
use Waaseyaa\Media\LocalFileRepository;

/**
 * @covers \Waaseyaa\Media\LocalFileRepository
 */
final class LocalFileRepositoryTest extends TestCase
{
    private string $rootDir;
    private LocalFileRepository $repository;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir() . '/waaseyaa_local_files_' . uniqid();
        $this->repository = new LocalFileRepository($this->rootDir);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->rootDir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->rootDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->rootDir);
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(FileRepositoryInterface::class, $this->repository);
    }

    public function testSaveAndLoadRoundTripsOriginalName(): void
    {
        // Non-ASCII (Anishinaabemowin) original names must survive the JSON
        // sidecar byte-for-byte while the disk-facing filename stays sanitized.
        $file = new File(
            uri: 'public://Ozhibii_igan_ab12cd34.png',
            filename: 'Ozhibii_igan_ab12cd34.png',
            mimeType: 'image/png',
            originalName: 'Ozhibiiʼigan ᐊᓂᔑᓈᐯᒧᐎᓐ.png',
        );

        $this->repository->save($file);
        $loaded = $this->repository->load('public://Ozhibii_igan_ab12cd34.png');

        $this->assertNotNull($loaded);
        $this->assertSame('Ozhibiiʼigan ᐊᓂᔑᓈᐯᒧᐎᓐ.png', $loaded->originalName);
        $this->assertSame('Ozhibii_igan_ab12cd34.png', $loaded->filename);
    }

    public function testLoadWithoutOriginalNameYieldsNull(): void
    {
        $file = new File(uri: 'public://plain.txt', filename: 'plain.txt');

        $this->repository->save($file);
        $loaded = $this->repository->load('public://plain.txt');

        $this->assertNotNull($loaded);
        $this->assertNull($loaded->originalName);
    }

    public function testConstructorCreatesRootDirectory(): void
    {
        $this->assertDirectoryExists($this->rootDir);
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $file = new File(
            uri: 'public://images/2026/photo.jpg',
            filename: 'photo.jpg',
            mimeType: 'image/jpeg',
            size: 1024,
            ownerId: 7,
            createdTime: 1700000000,
        );

        $this->repository->save($file);
        $loaded = $this->repository->load($file->uri);

        $this->assertNotNull($loaded);
        $this->assertSame($file->uri, $loaded->uri);
        $this->assertSame($file->filename, $loaded->filename);
        $this->assertSame($file->mimeType, $loaded->mimeType);
        $this->assertSame($file->size, $loaded->size);
        $this->assertSame($file->ownerId, $loaded->ownerId);
    }

    public function testPersistenceAcrossRepositoryInstances(): void
    {
        $file = new File(uri: 'public://docs/readme.pdf', filename: 'readme.pdf', ownerId: 2);
        $this->repository->save($file);

        $freshRepository = new LocalFileRepository($this->rootDir);
        $loaded = $freshRepository->load($file->uri);

        $this->assertNotNull($loaded);
        $this->assertSame('readme.pdf', $loaded->filename);
        $this->assertSame(2, $loaded->ownerId);
    }

    public function testDeleteExistingFile(): void
    {
        $file = new File(uri: 'public://tmp/to-delete.txt', filename: 'to-delete.txt');
        $this->repository->save($file);

        $deleted = $this->repository->delete($file->uri);

        $this->assertTrue($deleted);
        $this->assertNull($this->repository->load($file->uri));
    }

    public function testDeleteNonExistentReturnsFalse(): void
    {
        $this->assertFalse($this->repository->delete('public://missing.txt'));
    }

    public function testFindByOwnerReturnsMatchingFilesOnly(): void
    {
        $this->repository->save(new File(uri: 'public://a.txt', filename: 'a.txt', ownerId: 1));
        $this->repository->save(new File(uri: 'public://b.txt', filename: 'b.txt', ownerId: 2));
        $this->repository->save(new File(uri: 'public://c.txt', filename: 'c.txt', ownerId: 1));

        $ownerOneFiles = $this->repository->findByOwner(1);

        $this->assertCount(2, $ownerOneFiles);
        $this->assertSame('public://a.txt', $ownerOneFiles[0]->uri);
        $this->assertSame('public://c.txt', $ownerOneFiles[1]->uri);
    }

    public function testFindByOwnerReturnsEmptyWhenNoMatches(): void
    {
        $this->repository->save(new File(uri: 'public://a.txt', filename: 'a.txt', ownerId: 1));

        $result = $this->repository->findByOwner(999);

        $this->assertSame([], $result);
    }

    public function testSaveSanitizesPathTraversalInUri(): void
    {
        $file = new File(uri: 'public://../../etc/passwd', filename: 'passwd');
        $this->repository->save($file);

        $loaded = $this->repository->load($file->uri);
        $this->assertNotNull($loaded);
        $this->assertSame('passwd', $loaded->filename);
    }
}
