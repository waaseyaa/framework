<?php

declare(strict_types=1);

namespace Waaseyaa\Path\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
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
        $query->method('condition')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn([10]);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);
        $storage->method('load')->with(10)->willReturn($alias);

        $resolver = new PathAliasResolver($storage);
        $resolved = $resolver->resolve('/teaching/water-is-life');

        $this->assertInstanceOf(ResolvedPath::class, $resolved);
        $this->assertSame('node', $resolved->entityTypeId);
        $this->assertSame(42, $resolved->entityId);
    }

    #[Test]
    public function returns_null_when_alias_not_found(): void
    {
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('condition')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn([]);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        $resolver = new PathAliasResolver($storage);
        $this->assertNull($resolver->resolve('/missing'));
    }
}
