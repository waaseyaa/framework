<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\SqliteTopology;

#[CoversClass(SqliteTopology::class)]
final class SqliteTopologyTest extends TestCase
{
    #[Test]
    public function invalidExplicitEnvironmentDoesNotFallThroughToDevelopmentProcessState(): void
    {
        $originalEnvironment = getenv('APP_ENV');
        putenv('APP_ENV=local');

        try {
            self::assertSame('production', SqliteTopology::resolveEnvironment(['environment' => null]));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage(SqliteTopology::PRODUCTION_MEMORY);
            SqliteTopology::assertEnvironmentAllowsPath(
                ':memory:',
                SqliteTopology::resolveEnvironment(['environment' => null]),
            );
        } finally {
            $originalEnvironment === false
                ? putenv('APP_ENV')
                : putenv('APP_ENV=' . $originalEnvironment);
        }
    }

    #[Test]
    public function lowLayerDevelopmentAllowlistMatchesCanonicalNormalizedSemantics(): void
    {
        foreach (['local', 'dev', 'development', 'testing'] as $environment) {
            SqliteTopology::assertEnvironmentAllowsPath(':memory:', $environment);
            SqliteTopology::assertEnvironmentAllowsPath(':memory:', strtoupper($environment));
            SqliteTopology::assertEnvironmentAllowsPath(':memory:', ' ' . $environment . ' ');
        }

        $this->addToAssertionCount(12);
    }
}
