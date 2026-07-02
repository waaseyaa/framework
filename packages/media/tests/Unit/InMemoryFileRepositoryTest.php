<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Media\File;
use Waaseyaa\Media\FileRepositoryInterface;
use Waaseyaa\Media\InMemoryFileRepository;

/**
 * @covers \Waaseyaa\Media\InMemoryFileRepository
 */
final class InMemoryFileRepositoryTest extends TestCase
{
    private InMemoryFileRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryFileRepository();
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(FileRepositoryInterface::class, $this->repository);
    }

    public function testSaveAndLoad(): void
    {
        $file = new File(
            uri: 'public://images/photo.jpg',
            filename: 'photo.jpg',
            mimeType: 'image/jpeg',
            size: 102400,
        );

        $saved = $this->repository->save($file);

        $this->assertSame($file, $saved);

        $loaded = $this->repository->load('public://images/photo.jpg');

        $this->assertNotNull($loaded);
        $this->assertSame('public://images/photo.jpg', $loaded->uri);
        $this->assertSame('photo.jpg', $loaded->filename);
        $this->assertSame('image/jpeg', $loaded->mimeType);
        $this->assertSame(102400, $loaded->size);
    }

    public function testLoadReturnsNullForNonExistent(): void
    {
        $this->assertNull($this->repository->load('public://nonexistent.txt'));
    }

    public function testSaveOverwritesExistingFile(): void
    {
        $file1 = new File(
            uri: 'public://doc.pdf',
            filename: 'doc.pdf',
            mimeType: 'application/pdf',
            size: 5000,
        );
        $this->repository->save($file1);

        $file2 = new File(
            uri: 'public://doc.pdf',
            filename: 'doc.pdf',
            mimeType: 'application/pdf',
            size: 10000,
        );
        $this->repository->save($file2);

        $loaded = $this->repository->load('public://doc.pdf');

        $this->assertNotNull($loaded);
        $this->assertSame(10000, $loaded->size);
    }

    public function testDeleteExistingFile(): void
    {
        $file = new File(
            uri: 'public://to-delete.txt',
            filename: 'to-delete.txt',
        );
        $this->repository->save($file);

        $result = $this->repository->delete('public://to-delete.txt');

        $this->assertTrue($result);
        $this->assertNull($this->repository->load('public://to-delete.txt'));
    }

    public function testDeleteNonExistentReturnsFalse(): void
    {
        $result = $this->repository->delete('public://nonexistent.txt');

        $this->assertFalse($result);
    }

    public function testDeleteDoesNotAffectOtherFiles(): void
    {
        $file1 = new File(uri: 'public://keep.txt', filename: 'keep.txt');
        $file2 = new File(uri: 'public://delete.txt', filename: 'delete.txt');

        $this->repository->save($file1);
        $this->repository->save($file2);

        $this->repository->delete('public://delete.txt');

        $this->assertNotNull($this->repository->load('public://keep.txt'));
        $this->assertNull($this->repository->load('public://delete.txt'));
    }

    public function testFindByOwner(): void
    {
        $this->repository->save(new File(
            uri: 'public://user1-file1.jpg',
            filename: 'file1.jpg',
            ownerId: 1,
        ));
        $this->repository->save(new File(
            uri: 'public://user1-file2.png',
            filename: 'file2.png',
            ownerId: 1,
        ));
        $this->repository->save(new File(
            uri: 'public://user2-file1.pdf',
            filename: 'file1.pdf',
            ownerId: 2,
        ));
        $this->repository->save(new File(
            uri: 'public://no-owner.txt',
            filename: 'no-owner.txt',
        ));

        $user1Files = $this->repository->findByOwner(1);

        $this->assertCount(2, $user1Files);
        $this->assertSame('public://user1-file1.jpg', $user1Files[0]->uri);
        $this->assertSame('public://user1-file2.png', $user1Files[1]->uri);
    }

    public function testFindByOwnerReturnsEmptyArrayWhenNoneFound(): void
    {
        $this->repository->save(new File(
            uri: 'public://file.txt',
            filename: 'file.txt',
            ownerId: 1,
        ));

        $result = $this->repository->findByOwner(999);

        $this->assertSame([], $result);
    }

    public function testFindByOwnerDoesNotIncludeNullOwner(): void
    {
        $this->repository->save(new File(
            uri: 'public://orphan.txt',
            filename: 'orphan.txt',
        ));

        $result = $this->repository->findByOwner(0);

        $this->assertSame([], $result);
    }

    public function testFindByOwnerSingleFile(): void
    {
        $this->repository->save(new File(
            uri: 'public://only.txt',
            filename: 'only.txt',
            ownerId: 42,
        ));

        $result = $this->repository->findByOwner(42);

        $this->assertCount(1, $result);
        $this->assertSame('public://only.txt', $result[0]->uri);
    }

    public function testSaveMultipleFilesAndLoadEach(): void
    {
        $files = [
            new File(uri: 'public://a.txt', filename: 'a.txt'),
            new File(uri: 'public://b.txt', filename: 'b.txt'),
            new File(uri: 'public://c.txt', filename: 'c.txt'),
        ];

        foreach ($files as $file) {
            $this->repository->save($file);
        }

        foreach ($files as $file) {
            $loaded = $this->repository->load($file->uri);
            $this->assertNotNull($loaded);
            $this->assertSame($file->filename, $loaded->filename);
        }
    }

    public function testEmptyRepositoryFindByOwnerReturnsEmpty(): void
    {
        $this->assertSame([], $this->repository->findByOwner(1));
    }
}
