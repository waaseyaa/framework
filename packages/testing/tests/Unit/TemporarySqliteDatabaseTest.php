<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Testing\Database\TemporarySqliteDatabase;

#[CoversClass(TemporarySqliteDatabase::class)]
final class TemporarySqliteDatabaseTest extends TestCase
{
    #[Test]
    public function it_exposes_the_real_database_contract_and_cleans_up_its_file(): void
    {
        $fixture = new TemporarySqliteDatabase();
        $path = $fixture->path();
        $database = $fixture->database();

        $database->query('CREATE TABLE example (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $database->insert('example')->fields(['name'])->values(['Aki'])->execute();

        self::assertSame([['name' => 'Aki']], iterator_to_array($database->query('SELECT name FROM example')));
        self::assertFileExists($path);

        $fixture->remove();
        self::assertFileDoesNotExist($path);
    }
}
