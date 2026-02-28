<?php

declare(strict_types=1);

namespace Aurora\EntityStorage\Tests\Unit\Connection;

use Aurora\Database\PdoDatabase;
use Aurora\EntityStorage\Connection\ConnectionResolverInterface;
use Aurora\EntityStorage\Connection\SingleConnectionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SingleConnectionResolver::class)]
final class SingleConnectionResolverTest extends TestCase
{
    #[Test]
    public function implementsInterface(): void
    {
        $db = PdoDatabase::createSqlite();
        $resolver = new SingleConnectionResolver($db);

        $this->assertInstanceOf(ConnectionResolverInterface::class, $resolver);
    }

    #[Test]
    public function connectionAlwaysReturnsSameInstance(): void
    {
        $db = PdoDatabase::createSqlite();
        $resolver = new SingleConnectionResolver($db);

        $this->assertSame($db, $resolver->connection());
        $this->assertSame($db, $resolver->connection('anything'));
        $this->assertSame($db, $resolver->connection(null));
        $this->assertSame($db, $resolver->connection('other'));
    }

    #[Test]
    public function getDefaultConnectionNameReturnsDefault(): void
    {
        $db = PdoDatabase::createSqlite();
        $resolver = new SingleConnectionResolver($db);

        $this->assertSame('default', $resolver->getDefaultConnectionName());
    }
}
