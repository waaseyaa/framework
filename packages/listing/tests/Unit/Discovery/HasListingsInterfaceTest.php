<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use Waaseyaa\Listing\HasListingsInterface;
use Waaseyaa\Listing\ListingDefinition;

#[CoversNothing]
final class HasListingsInterfaceTest extends TestCase
{
    #[Test]
    public function declaresListingsMethodReturningArray(): void
    {
        $method = new ReflectionMethod(HasListingsInterface::class, 'listings');

        self::assertTrue($method->isPublic(), 'listings() must be public');
        self::assertSame(0, $method->getNumberOfParameters(), 'listings() must take no params');

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
        self::assertFalse($returnType->allowsNull());
    }

    #[Test]
    public function implementorCanReturnListOfListingDefinitions(): void
    {
        $provider = new class () implements HasListingsInterface {
            /** @return list<ListingDefinition> */
            public function listings(): array
            {
                return [
                    new ListingDefinition(id: 'a', entityType: 'node'),
                    new ListingDefinition(id: 'b', entityType: 'node'),
                ];
            }
        };

        $listings = $provider->listings();

        self::assertCount(2, $listings);
        self::assertContainsOnlyInstancesOf(ListingDefinition::class, $listings);
        self::assertSame('a', $listings[0]->id);
        self::assertSame('b', $listings[1]->id);
    }

    #[Test]
    public function implementorCanReturnEmptyList(): void
    {
        $provider = new class () implements HasListingsInterface {
            /** @return list<ListingDefinition> */
            public function listings(): array
            {
                return [];
            }
        };

        self::assertSame([], $provider->listings());
    }
}
