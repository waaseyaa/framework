<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;

final class DatabaseIdentityProviderTest extends TestCase
{
    #[Test]
    public function identityIsStableAndDoesNotDiscloseTheDatabasePath(): void
    {
        $path = sys_get_temp_dir() . '/waaseyaa_identity_' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $first = DBALDatabase::createSqlite($path, 'testing')->databaseIdentity();
            $second = DBALDatabase::createSqlite($path, 'testing')->databaseIdentity();

            self::assertSame($first, $second);
            self::assertMatchesRegularExpression('/^database:v1:[a-f0-9]{64}$/D', $first);
            self::assertStringNotContainsString($path, $first);
        } finally {
            @unlink($path);
        }
    }
}
