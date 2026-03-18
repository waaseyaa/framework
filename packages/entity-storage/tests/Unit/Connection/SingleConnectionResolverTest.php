<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Connection;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Connection\ConnectionResolverInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SingleConnectionResolver::class)]
final class SingleConnectionResolverTest extends TestCase
{
    #[Test]
    public function implementsInterface(): void
    {
        $db = DBALDatabase::createSqlite();
        $resolver = new SingleConnectionResolver($db);

        $this->assertInstanceOf(ConnectionResolverInterface::class, $resolver);
    }

    #[Test]
    public function connectionAlwaysReturnsSameInstance(): void
    {
        $db = DBALDatabase::createSqlite();
        $resolver = new SingleConnectionResolver($db);

        $this->assertSame($db, $resolver->connection());
        $this->assertSame($db, $resolver->connection('anything'));
        $this->assertSame($db, $resolver->connection(null));
        $this->assertSame($db, $resolver->connection('other'));
    }

    #[Test]
    public function getDefaultConnectionNameReturnsDefault(): void
    {
        $db = DBALDatabase::createSqlite();
        $resolver = new SingleConnectionResolver($db);

        $this->assertSame('default', $resolver->getDefaultConnectionName());
    }
}
