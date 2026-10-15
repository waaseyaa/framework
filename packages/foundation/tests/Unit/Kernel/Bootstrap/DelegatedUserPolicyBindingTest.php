<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Bootstrap;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\Bootstrap\AccessPolicyRegistry;
use Waaseyaa\Foundation\Kernel\Bootstrap\KernelPolicyDependencyResolver;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;
use Waaseyaa\User\UserManagementDelegateInterface;

final class DelegatedUserPolicyBindingTest extends TestCase
{
    public function testAbsentDelegateRetainsTheCanonicalRefusal(): void
    {
        $services = $this->createStub(KernelServicesInterface::class);
        $services->method('get')->willReturn(null);
        $handler = (new AccessPolicyRegistry(new NullLogger(), new KernelPolicyDependencyResolver($services)))
            ->discover(new PackageManifest(policies: [UserAccessPolicy::class => ['user']]));
        $actor = new AuthorizationPrincipal(10, true, [], ['consumer.manage'], 'unbound');
        self::assertFalse($handler->checkCreateAccess('user', 'user', $actor)->isAllowed());
        self::assertFalse($handler->check(new User(['uid' => 20]), 'update', $actor)->isAllowed());
    }

    public function testBoundDelegateSurvivesDiscoveryWithoutRetainingAnActor(): void
    {
        $delegate = new class implements UserManagementDelegateInterface {
            public function canUpdate(User $target, AuthorizationPrincipalInterface $actor): bool { return $actor->id() === 10 && $target->id() === 20; }
            public function canChangeActiveStatus(User $target, AuthorizationPrincipalInterface $actor): bool { return $actor->id() === 10; }
        };
        $services = $this->createStub(KernelServicesInterface::class);
        $services->method('get')->willReturnCallback(static fn (string $type): ?object => $type === UserManagementDelegateInterface::class ? $delegate : null);
        $handler = (new AccessPolicyRegistry(new NullLogger(), new KernelPolicyDependencyResolver($services)))
            ->discover(new PackageManifest(policies: [UserAccessPolicy::class => ['user']]));
        $target = new User(['uid' => 20]);
        foreach ([10 => true, 11 => false] as $id => $allowed) {
            $actor = new AuthorizationPrincipal($id, true, [], ['consumer.manage'], 'bound-'.$id);
            self::assertFalse($handler->checkCreateAccess('user', 'user', $actor)->isAllowed());
            self::assertSame($allowed, $handler->check($target, 'update', $actor)->isAllowed());
        }
        self::assertTrue($handler->check($target, 'update', new AuthorizationPrincipal(10, true, [], ['consumer.manage'], 'bound-again'))->isAllowed());
    }
}
