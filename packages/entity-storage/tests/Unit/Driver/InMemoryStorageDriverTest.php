<?php

declare(strict_types=1);

namespace Aurora\EntityStorage\Tests\Unit\Driver;

use Aurora\EntityStorage\Driver\EntityStorageDriverInterface;
use Aurora\EntityStorage\Driver\InMemoryStorageDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryStorageDriver::class)]
final class InMemoryStorageDriverTest extends TestCase
{
    private InMemoryStorageDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new InMemoryStorageDriver();
    }

    #[Test]
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(EntityStorageDriverInterface::class, $this->driver);
    }

    #[Test]
    public function writeAndRead(): void
    {
        $this->driver->write('node', '1', [
            'id' => '1',
            'label' => 'Hello World',
            'bundle' => 'article',
        ]);

        $row = $this->driver->read('node', '1');

        $this->assertNotNull($row);
        $this->assertSame('1', $row['id']);
        $this->assertSame('Hello World', $row['label']);
        $this->assertSame('article', $row['bundle']);
    }

    #[Test]
    public function readReturnsNullForMissing(): void
    {
        $this->assertNull($this->driver->read('node', '999'));
    }

    #[Test]
    public function writeOverwritesExisting(): void
    {
        $this->driver->write('node', '1', ['id' => '1', 'label' => 'Original']);
        $this->driver->write('node', '1', ['id' => '1', 'label' => 'Updated']);

        $row = $this->driver->read('node', '1');
        $this->assertSame('Updated', $row['label']);
    }

    #[Test]
    public function remove(): void
    {
        $this->driver->write('node', '1', ['id' => '1', 'label' => 'Delete Me']);
        $this->driver->remove('node', '1');

        $this->assertNull($this->driver->read('node', '1'));
    }

    #[Test]
    public function exists(): void
    {
        $this->assertFalse($this->driver->exists('node', '1'));

        $this->driver->write('node', '1', ['id' => '1', 'label' => 'Exists']);

        $this->assertTrue($this->driver->exists('node', '1'));

        $this->driver->remove('node', '1');

        $this->assertFalse($this->driver->exists('node', '1'));
    }

    #[Test]
    public function countWithoutCriteria(): void
    {
        $this->assertSame(0, $this->driver->count('node'));

        $this->driver->write('node', '1', ['id' => '1', 'label' => 'One']);
        $this->driver->write('node', '2', ['id' => '2', 'label' => 'Two']);

        $this->assertSame(2, $this->driver->count('node'));
    }

    #[Test]
    public function countWithCriteria(): void
    {
        $this->driver->write('node', '1', ['id' => '1', 'bundle' => 'article']);
        $this->driver->write('node', '2', ['id' => '2', 'bundle' => 'page']);
        $this->driver->write('node', '3', ['id' => '3', 'bundle' => 'article']);

        $this->assertSame(2, $this->driver->count('node', ['bundle' => 'article']));
        $this->assertSame(1, $this->driver->count('node', ['bundle' => 'page']));
    }

    #[Test]
    public function findByWithCriteria(): void
    {
        $this->driver->write('node', '1', ['id' => '1', 'bundle' => 'article', 'label' => 'A']);
        $this->driver->write('node', '2', ['id' => '2', 'bundle' => 'page', 'label' => 'B']);
        $this->driver->write('node', '3', ['id' => '3', 'bundle' => 'article', 'label' => 'C']);

        $results = $this->driver->findBy('node', ['bundle' => 'article']);

        $this->assertCount(2, $results);
        $labels = array_column($results, 'label');
        $this->assertContains('A', $labels);
        $this->assertContains('C', $labels);
    }

    #[Test]
    public function findByWithOrderBy(): void
    {
        $this->driver->write('node', '1', ['id' => '1', 'label' => 'Bravo']);
        $this->driver->write('node', '2', ['id' => '2', 'label' => 'Alpha']);
        $this->driver->write('node', '3', ['id' => '3', 'label' => 'Charlie']);

        $results = $this->driver->findBy('node', [], ['label' => 'ASC']);

        $this->assertSame('Alpha', $results[0]['label']);
        $this->assertSame('Bravo', $results[1]['label']);
        $this->assertSame('Charlie', $results[2]['label']);

        $resultsDesc = $this->driver->findBy('node', [], ['label' => 'DESC']);

        $this->assertSame('Charlie', $resultsDesc[0]['label']);
        $this->assertSame('Bravo', $resultsDesc[1]['label']);
        $this->assertSame('Alpha', $resultsDesc[2]['label']);
    }

    #[Test]
    public function findByWithLimit(): void
    {
        $this->driver->write('node', '1', ['id' => '1', 'label' => 'One']);
        $this->driver->write('node', '2', ['id' => '2', 'label' => 'Two']);
        $this->driver->write('node', '3', ['id' => '3', 'label' => 'Three']);

        $results = $this->driver->findBy('node', [], null, 2);

        $this->assertCount(2, $results);
    }

    #[Test]
    public function findByReturnsEmptyForNonExistentType(): void
    {
        $this->assertSame([], $this->driver->findBy('nonexistent'));
    }

    #[Test]
    public function translationWriteAndRead(): void
    {
        $this->driver->write('node', '1', ['id' => '1', 'label' => 'Hello']);
        $this->driver->writeTranslation('node', '1', 'fr', ['label' => 'Bonjour', 'langcode' => 'fr']);

        // Read base.
        $base = $this->driver->read('node', '1');
        $this->assertSame('Hello', $base['label']);

        // Read French translation.
        $fr = $this->driver->read('node', '1', 'fr');
        $this->assertSame('Bonjour', $fr['label']);
        $this->assertSame('fr', $fr['langcode']);
    }

    #[Test]
    public function readWithMissingTranslationReturnsNull(): void
    {
        $this->driver->write('node', '1', ['id' => '1', 'label' => 'Hello']);
        $this->driver->writeTranslation('node', '1', 'fr', ['label' => 'Bonjour']);

        // German not available, but translations exist, so returns null.
        $this->assertNull($this->driver->read('node', '1', 'de'));
    }

    #[Test]
    public function deleteTranslation(): void
    {
        $this->driver->write('node', '1', ['id' => '1', 'label' => 'Hello']);
        $this->driver->writeTranslation('node', '1', 'fr', ['label' => 'Bonjour']);

        $this->driver->deleteTranslation('node', '1', 'fr');

        // French translation no longer available.
        $this->assertSame([], $this->driver->getAvailableLanguages('node', '1'));
    }

    #[Test]
    public function getAvailableLanguages(): void
    {
        $this->driver->write('node', '1', ['id' => '1', 'label' => 'Hello']);
        $this->driver->writeTranslation('node', '1', 'en', ['label' => 'Hello']);
        $this->driver->writeTranslation('node', '1', 'fr', ['label' => 'Bonjour']);
        $this->driver->writeTranslation('node', '1', 'de', ['label' => 'Hallo']);

        $languages = $this->driver->getAvailableLanguages('node', '1');

        $this->assertCount(3, $languages);
        $this->assertContains('en', $languages);
        $this->assertContains('fr', $languages);
        $this->assertContains('de', $languages);
    }

    #[Test]
    public function removeAlsoRemovesTranslations(): void
    {
        $this->driver->write('node', '1', ['id' => '1', 'label' => 'Hello']);
        $this->driver->writeTranslation('node', '1', 'fr', ['label' => 'Bonjour']);

        $this->driver->remove('node', '1');

        $this->assertNull($this->driver->read('node', '1'));
        $this->assertSame([], $this->driver->getAvailableLanguages('node', '1'));
    }

    #[Test]
    public function clear(): void
    {
        $this->driver->write('node', '1', ['id' => '1', 'label' => 'Hello']);
        $this->driver->writeTranslation('node', '1', 'fr', ['label' => 'Bonjour']);

        $this->driver->clear();

        $this->assertNull($this->driver->read('node', '1'));
        $this->assertSame(0, $this->driver->count('node'));
    }
}
