<?php

declare(strict_types=1);

namespace Waaseyaa\Path\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Path\PathAlias;
use Waaseyaa\Path\PathAliasResolver;
use Waaseyaa\Path\ResolvedPath;

#[CoversClass(PathAliasResolver::class)]
final class PathAliasResolverTest extends TestCase
{
    #[Test]
    public function resolves_alias_to_entity_target(): void
    {
        $alias = new PathAlias([
            'id' => 10,
            'path' => '/node/42',
            'alias' => '/teaching/water-is-life',
            'langcode' => 'en',
            'status' => true,
        ]);

        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn([10]);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $repository->method('find')->with('10')->willReturn($alias);

        $resolver = new PathAliasResolver($repository);
        $resolved = $resolver->resolve('/teaching/water-is-life');

        $this->assertInstanceOf(ResolvedPath::class, $resolved);
        $this->assertSame('node', $resolved->entityTypeId);
        $this->assertSame(42, $resolved->entityId);
    }

    #[Test]
    public function returns_null_when_alias_not_found(): void
    {
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn([]);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);

        $resolver = new PathAliasResolver($repository);
        $this->assertNull($resolver->resolve('/missing'));
    }

    #[Test]
    public function normalizesTrailingSlashWhilePreservingLanguageScope(): void
    {
        $conditions = [];
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnCallback(
            function (string $field, mixed $value) use (&$conditions, $query): EntityQueryInterface {
                $conditions[$field] = $value;
                return $query;
            },
        );
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn([]);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);

        new PathAliasResolver($repository)->resolve('/about/', 'oj');

        $this->assertSame('/about', $conditions['alias']);
        $this->assertSame('oj', $conditions['langcode']);
    }
}
