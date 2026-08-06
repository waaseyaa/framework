<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Search\SearchProviderInterface;

#[CoversNothing]
final class SearchProviderPrincipalContractTest extends TestCase
{
    #[Test]
    public function search_requires_a_separate_non_nullable_authorization_principal(): void
    {
        $method = new \ReflectionMethod(SearchProviderInterface::class, 'search');
        $parameters = $method->getParameters();

        self::assertCount(2, $parameters);
        self::assertSame('request', $parameters[0]->getName());
        self::assertSame('principal', $parameters[1]->getName());
        self::assertFalse($parameters[1]->isDefaultValueAvailable());
        self::assertFalse($parameters[1]->allowsNull());
        self::assertSame(AuthorizationPrincipalInterface::class, (string) $parameters[1]->getType());
    }
}
