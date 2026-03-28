<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Engagement\EngagementAccessPolicy;
use Waaseyaa\Entity\EntityInterface;

#[CoversClass(EngagementAccessPolicy::class)]
final class EngagementAccessPolicyTest extends TestCase
{
    private EngagementAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new EngagementAccessPolicy();
    }

    #[Test]
    public function applies_to_engagement_types(): void
    {
        $this->assertTrue($this->policy->appliesTo('reaction'));
        $this->assertTrue($this->policy->appliesTo('comment'));
        $this->assertTrue($this->policy->appliesTo('follow'));
        $this->assertFalse($this->policy->appliesTo('post'));
    }

    #[Test]
    public function admin_is_always_allowed(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->with('administer content')->willReturn(true);
        $entity = $this->createMock(EntityInterface::class);

        $result = $this->policy->access($entity, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function view_is_publicly_allowed(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $entity = $this->createMock(EntityInterface::class);

        $result = $this->policy->access($entity, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function owner_can_delete(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('id')->willReturn(42);

        $entity = $this->createMock(EntityInterface::class);
        $entity->method('get')->with('user_id')->willReturn(42);

        $result = $this->policy->access($entity, 'delete', $account);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function non_owner_cannot_delete(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('id')->willReturn(99);

        $entity = $this->createMock(EntityInterface::class);
        $entity->method('get')->with('user_id')->willReturn(42);

        $result = $this->policy->access($entity, 'delete', $account);
        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function authenticated_can_create(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('isAuthenticated')->willReturn(true);

        $result = $this->policy->createAccess('reaction', 'reaction', $account);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function anonymous_cannot_create(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('isAuthenticated')->willReturn(false);

        $result = $this->policy->createAccess('reaction', 'reaction', $account);
        $this->assertTrue($result->isNeutral());
    }
}
