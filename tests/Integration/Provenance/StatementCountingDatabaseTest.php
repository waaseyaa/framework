<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Provenance;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Tests\Support\StatementCountingDatabase;

/**
 * #2542: save-time schema probes (`tableExists` / `fieldExists`) reach SQLite
 * through Doctrine and must not be invisible to the NFR. Setup DDL stays on
 * the undecorated handle (S1-DB107); counting `schema()` construction here
 * does not put table creation on the decorator.
 *
 * #[CoversNothing]: the decorator lives under tests/Support, outside the
 * coverage source include (phpunit.xml.dist: packages src). CoversClass on it
 * is a PHPUnit warning, and failOnWarning turns that into a red shard.
 */
#[CoversNothing]
final class StatementCountingDatabaseTest extends TestCase
{
    #[Test]
    public function schema_construction_is_counted_so_an_attributed_probe_cannot_hide(): void
    {
        $schema = $this->createStub(SchemaInterface::class);
        $inner = $this->createStub(DatabaseInterface::class);
        $inner->method('schema')->willReturn($schema);

        $counter = new StatementCountingDatabase($inner);

        self::assertSame($schema, $counter->schema());
        self::assertSame(
            1,
            $counter->counts()['schema'],
            'schema() is the seam save-time tableExists/fieldExists go through. '
            . 'Leaving it uncounted would let an attributed-only probe pass NFR-001.',
        );
    }

    #[Test]
    public function quote_identifier_is_still_not_counted(): void
    {
        $inner = $this->createStub(DatabaseInterface::class);
        $inner->method('quoteIdentifier')->willReturn('"title"');

        $counter = new StatementCountingDatabase($inner);
        self::assertSame('"title"', $counter->quoteIdentifier('title'));
        self::assertArrayNotHasKey('quoteIdentifier', $counter->counts());
        self::assertSame(0, $counter->total());
    }
}
