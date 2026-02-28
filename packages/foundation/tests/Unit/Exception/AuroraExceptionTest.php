<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Exception;

use Aurora\Foundation\Exception\AuroraException;
use Aurora\Foundation\Exception\AuthenticationException;
use Aurora\Foundation\Exception\ConfigException;
use Aurora\Foundation\Exception\StorageException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuroraException::class)]
#[CoversClass(StorageException::class)]
#[CoversClass(ConfigException::class)]
#[CoversClass(AuthenticationException::class)]
final class AuroraExceptionTest extends TestCase
{
    #[Test]
    public function storage_exception_has_correct_defaults(): void
    {
        $e = new StorageException('Database is down');

        $this->assertSame('Database is down', $e->getMessage());
        $this->assertSame(503, $e->statusCode);
        $this->assertSame('aurora:storage/error', $e->problemType);
        $this->assertInstanceOf(AuroraException::class, $e);
    }

    #[Test]
    public function config_exception_has_correct_defaults(): void
    {
        $e = new ConfigException('Invalid YAML');

        $this->assertSame(500, $e->statusCode);
        $this->assertSame('aurora:config/error', $e->problemType);
    }

    #[Test]
    public function authentication_exception_has_correct_defaults(): void
    {
        $e = new AuthenticationException('Invalid token');

        $this->assertSame(401, $e->statusCode);
        $this->assertSame('aurora:auth/error', $e->problemType);
    }

    #[Test]
    public function exception_carries_context(): void
    {
        $e = new StorageException(
            'Query failed',
            context: ['query' => 'SELECT * FROM nodes', 'table' => 'nodes'],
        );

        $this->assertSame('SELECT * FROM nodes', $e->context['query']);
    }

    #[Test]
    public function exception_wraps_previous(): void
    {
        $pdo = new \PDOException('Connection refused');
        $e = new StorageException('Database is down', previous: $pdo);

        $this->assertSame($pdo, $e->getPrevious());
    }

    #[Test]
    public function to_api_error_returns_rfc9457_array(): void
    {
        $e = new StorageException('Database is down');
        $error = $e->toApiError();

        $this->assertSame('aurora:storage/error', $error['type']);
        $this->assertSame('Database is down', $error['detail']);
        $this->assertSame(503, $error['status']);
    }
}
