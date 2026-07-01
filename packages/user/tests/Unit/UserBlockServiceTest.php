<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\User\UserBlockService;

#[CoversClass(UserBlockService::class)]
final class UserBlockServiceTest extends TestCase
{
    #[Test]
    public function returns_true_when_block_exists(): void
    {
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn([1]);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getRepository')->with('user_block')->willReturn($repository);

        $service = new UserBlockService($etm);
        $this->assertTrue($service->isBlocked(42, 99));
    }

    #[Test]
    public function returns_false_when_no_block(): void
    {
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn([]);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getRepository')->with('user_block')->willReturn($repository);

        $service = new UserBlockService($etm);
        $this->assertFalse($service->isBlocked(42, 99));
    }

    #[Test]
    public function isBlocked_disables_access_check_for_integrity_lookup(): void
    {
        // Block-relationship existence is an integrity primitive: the yes/no
        // answer cannot be gated by either party's `view` policy on the
        // `user_block` entity. Mirrors RelationshipValidator and the
        // SqlEntityStorage::loadByKey (C-004) pattern; without the bypass,
        // SqlEntityQuery::execute() throws MissingQueryAccountException under
        // the fail-closed default introduced in v0.1.0-alpha.181.
        $query = $this->createMock(EntityQueryInterface::class);
        $query->expects($this->once())
            ->method('accessCheck')
            ->with(false)
            ->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn([]);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getRepository')->with('user_block')->willReturn($repository);

        $service = new UserBlockService($etm);
        $service->isBlocked(42, 99);
    }
}
