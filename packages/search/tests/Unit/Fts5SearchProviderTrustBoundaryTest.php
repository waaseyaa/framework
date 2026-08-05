<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Search\Access\SearchAccessChecker;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;

#[CoversClass(Fts5SearchProvider::class)]
final class Fts5SearchProviderTrustBoundaryTest extends TestCase
{
    #[Test]
    public function access_checker_is_a_mandatory_constructor_dependency(): void
    {
        $constructor = new \ReflectionMethod(Fts5SearchProvider::class, '__construct');
        $parameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        self::assertArrayHasKey('accessChecker', $parameters);
        $parameter = $parameters['accessChecker'];
        self::assertFalse($parameter->isDefaultValueAvailable());
        self::assertFalse($parameter->allowsNull());
        self::assertSame(SearchAccessChecker::class, (string) $parameter->getType());
    }
}
