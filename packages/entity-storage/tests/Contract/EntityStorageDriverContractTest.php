<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;

/**
 * Abstract contract test for EntityStorageDriverInterface.
 *
 * Any implementation of the interface should pass all these tests,
 * guaranteeing behavioural consistency across drivers.
 */
#[CoversNothing]
abstract class EntityStorageDriverContractTest extends TestCase
{
    protected EntityStorageDriverInterface $driver;

    abstract protected function createDriver(): EntityStorageDriverInterface;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = $this->createDriver();
    }

    // ── read / write ────────────────────────────────────────────────

    #[Test]
    public function writeThenReadReturnsSameValues(): void
    {
        $values = ['id' => '1', 'title' => 'Hello', 'status' => 'published'];

        $this->driver->write('test_entity', '1', $values);
        $result = $this->driver->read('test_entity', '1');

        self::assertNotNull($result);
        self::assertSame('1', $result['id']);
        self::assertSame('Hello', $result['title']);
        self::assertSame('published', $result['status']);
    }

    #[Test]
    public function readNonexistentReturnsNull(): void
    {
        $result = $this->driver->read('test_entity', 'nonexistent');

        self::assertNull($result);
    }

    #[Test]
    public function writeOverwritesExistingRow(): void
    {
        $this->driver->write('test_entity', '1', ['id' => '1', 'title' => 'Original']);
        $this->driver->write('test_entity', '1', ['id' => '1', 'title' => 'Updated']);

        $result = $this->driver->read('test_entity', '1');

        self::assertNotNull($result);
        self::assertSame('Updated', $result['title']);
    }

    // ── exists ──────────────────────────────────────────────────────

    #[Test]
    public function existsReturnsTrueForStoredEntity(): void
    {
        $this->driver->write('test_entity', '1', ['id' => '1', 'title' => 'Hello']);

        self::assertTrue($this->driver->exists('test_entity', '1'));
    }

    #[Test]
    public function existsReturnsFalseForMissingEntity(): void
    {
        self::assertFalse($this->driver->exists('test_entity', 'nonexistent'));
    }

    // ── remove ──────────────────────────────────────────────────────

    #[Test]
    public function removeThenReadReturnsNull(): void
    {
        $this->driver->write('test_entity', '1', ['id' => '1', 'title' => 'Hello']);
        $this->driver->remove('test_entity', '1');

        self::assertNull($this->driver->read('test_entity', '1'));
        self::assertFalse($this->driver->exists('test_entity', '1'));
    }

    #[Test]
    public function removeNonexistentDoesNotThrow(): void
    {
        // Should not throw even if the entity does not exist.
        $this->driver->remove('test_entity', 'nonexistent');
        self::assertFalse($this->driver->exists('test_entity', 'nonexistent'));
    }

    // ── count ───────────────────────────────────────────────────────

    #[Test]
    public function countWithNoCriteria(): void
    {
        $this->driver->write('test_entity', '1', ['id' => '1', 'title' => 'A', 'status' => 'draft']);
        $this->driver->write('test_entity', '2', ['id' => '2', 'title' => 'B', 'status' => 'published']);
        $this->driver->write('test_entity', '3', ['id' => '3', 'title' => 'C', 'status' => 'draft']);

        self::assertSame(3, $this->driver->count('test_entity'));
    }

    #[Test]
    public function countWithCriteriaFilter(): void
    {
        $this->driver->write('test_entity', '1', ['id' => '1', 'title' => 'A', 'status' => 'draft']);
        $this->driver->write('test_entity', '2', ['id' => '2', 'title' => 'B', 'status' => 'published']);
        $this->driver->write('test_entity', '3', ['id' => '3', 'title' => 'C', 'status' => 'draft']);

        self::assertSame(2, $this->driver->count('test_entity', ['status' => 'draft']));
        self::assertSame(1, $this->driver->count('test_entity', ['status' => 'published']));
    }

    #[Test]
    public function countReturnsZeroForEmptyEntityType(): void
    {
        self::assertSame(0, $this->driver->count('test_entity'));
    }

    // ── findBy ──────────────────────────────────────────────────────

    #[Test]
    public function findByReturnsMatchingRecords(): void
    {
        $this->driver->write('test_entity', '1', ['id' => '1', 'title' => 'A', 'status' => 'draft']);
        $this->driver->write('test_entity', '2', ['id' => '2', 'title' => 'B', 'status' => 'published']);
        $this->driver->write('test_entity', '3', ['id' => '3', 'title' => 'C', 'status' => 'draft']);

        $results = $this->driver->findBy('test_entity', ['status' => 'draft']);

        self::assertCount(2, $results);

        $ids = array_column($results, 'id');
        sort($ids);
        self::assertSame(['1', '3'], $ids);
    }

    #[Test]
    public function findByWithOrderBy(): void
    {
        $this->driver->write('test_entity', '1', ['id' => '1', 'title' => 'C']);
        $this->driver->write('test_entity', '2', ['id' => '2', 'title' => 'A']);
        $this->driver->write('test_entity', '3', ['id' => '3', 'title' => 'B']);

        $ascending = $this->driver->findBy('test_entity', [], ['title' => 'ASC']);
        self::assertSame(['A', 'B', 'C'], array_column($ascending, 'title'));

        $descending = $this->driver->findBy('test_entity', [], ['title' => 'DESC']);
        self::assertSame(['C', 'B', 'A'], array_column($descending, 'title'));
    }

    #[Test]
    public function findByWithLimit(): void
    {
        $this->driver->write('test_entity', '1', ['id' => '1', 'title' => 'A']);
        $this->driver->write('test_entity', '2', ['id' => '2', 'title' => 'B']);
        $this->driver->write('test_entity', '3', ['id' => '3', 'title' => 'C']);

        $results = $this->driver->findBy('test_entity', [], ['title' => 'ASC'], 2);

        self::assertCount(2, $results);
        self::assertSame('A', $results[0]['title']);
        self::assertSame('B', $results[1]['title']);
    }

    #[Test]
    public function findByReturnsEmptyArrayWhenNoMatch(): void
    {
        $this->driver->write('test_entity', '1', ['id' => '1', 'status' => 'draft']);

        $results = $this->driver->findBy('test_entity', ['status' => 'archived']);

        self::assertSame([], $results);
    }

    #[Test]
    public function findByWithNoCriteriaReturnsAll(): void
    {
        $this->driver->write('test_entity', '1', ['id' => '1', 'title' => 'A']);
        $this->driver->write('test_entity', '2', ['id' => '2', 'title' => 'B']);

        $results = $this->driver->findBy('test_entity');

        self::assertCount(2, $results);
    }
}
