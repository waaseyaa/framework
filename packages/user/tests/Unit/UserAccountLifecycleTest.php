<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;
use Waaseyaa\User\UserAccountLifecycle;
use Waaseyaa\User\UserManagementDelegateInterface;

final class UserAccountLifecycleTest extends TestCase
{
    public function testDisablingRevokesExistingSessionsInTheSameSave(): void
    {
        $user = new User(['uid' => 20, 'status' => true, 'session_generation' => 4]);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::callback(static function (User $saved): bool {
            return new UserInternalFieldReaderFixture()->verification($saved)->active === false && new UserInternalFieldReaderFixture()->sessionIdentity($saved)->generation === 5;
        }));
        $this->service(true)->setActive($repository, $user, false, $this->principal());
    }

    public function testReenablingDoesNotRestoreRevokedSessionGeneration(): void
    {
        $user = new User(['uid' => 20, 'status' => false, 'session_generation' => 5]);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::callback(static function (User $saved): bool {
            return new UserInternalFieldReaderFixture()->verification($saved)->active === true && new UserInternalFieldReaderFixture()->sessionIdentity($saved)->generation === 5;
        }));
        $this->service(false)->setActive($repository, $user, true, $this->principal());
    }

    public function testUnchangedStatusDoesNotWrite(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('save');
        $this->service(true)->setActive($repository, new User(['uid' => 20]), true, $this->principal());
    }

    public function testUnprivilegedCallerCannotDisableAnotherAccount(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('save');
        $this->expectException(\RuntimeException::class);
        $this->service(true)->setActive($repository, new User(['uid' => 20]), false, new AuthorizationPrincipal(30, true, [], [], 'test'));
    }

    public function testOwnAccountCannotBeManagedThroughThisBoundary(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('save');
        $this->expectException(\RuntimeException::class);
        $this->service(true)->setActive($repository, new User(['uid' => 10]), false, $this->principal());
    }

    private function principal(): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(10, true, ['clerk'], ['sfn_manage_members'], 'test');
    }

    private function service(bool $active): UserAccountLifecycle
    {
        $delegate = new class implements UserManagementDelegateInterface {
            public function canUpdate(User $target, AuthorizationPrincipalInterface $actor): bool
            {
                return $target->id() === 20 && $actor->id() === 10;
            }
            public function canChangeActiveStatus(User $target, AuthorizationPrincipalInterface $actor): bool
            {
                return true;
            }
        };
        $reader = new UserInternalFieldReaderFixture();
        return new UserAccountLifecycle(new EntityAccessHandler([new UserAccessPolicy($delegate)]), $reader);
    }
}
