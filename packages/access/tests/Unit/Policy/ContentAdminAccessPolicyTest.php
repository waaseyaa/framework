<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Policy\ContentAdminAccessPolicy;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

#[CoversClass(ContentAdminAccessPolicy::class)]
final class ContentAdminAccessPolicyTest extends TestCase
{
    /**
     * @param array<string, string> $groups Entity type id => group.
     */
    private function policy(array $groups): ContentAdminAccessPolicy
    {
        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturnCallback(static fn(string $id): bool => isset($groups[$id]));
        $etm->method('getDefinition')->willReturnCallback(function (string $id) use ($groups): EntityTypeInterface {
            $type = $this->createStub(EntityTypeInterface::class);
            $type->method('getGroup')->willReturn($groups[$id] ?? null);

            return $type;
        });

        return new ContentAdminAccessPolicy($etm);
    }

    private function entity(mixed $status = false): EntityInterface
    {
        $entity = $this->createStub(EntityInterface::class);
        $entity->method('get')->willReturnCallback(static fn(string $name): mixed => $name === 'status' ? $status : null);

        return $entity;
    }

    private function account(bool $isContentAdmin): AccountInterface
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('hasPermission')->willReturnCallback(
            static fn(string $perm): bool => $isContentAdmin && $perm === 'administer content',
        );

        return $account;
    }

    #[Test]
    public function applies_only_to_the_content_group(): void
    {
        $policy = $this->policy(['story' => 'content', 'user' => 'people', 'term' => 'taxonomy']);

        self::assertTrue($policy->appliesTo('story'));
        self::assertFalse($policy->appliesTo('user'));
        self::assertFalse($policy->appliesTo('term'));
        self::assertFalse($policy->appliesTo('ghost'), 'unknown type does not apply');
    }

    #[Test]
    public function grants_admin_full_manage_including_drafts(): void
    {
        $policy = $this->policy(['story' => 'content']);
        $admin = $this->account(isContentAdmin: true);
        $draft = $this->entity(status: false);

        // Drafts included — manage is not gated on publish state.
        self::assertTrue($policy->access($draft, 'view', $admin)->isAllowed());
        self::assertTrue($policy->access($draft, 'update', $admin)->isAllowed());
        self::assertTrue($policy->access($draft, 'delete', $admin)->isAllowed());
        self::assertTrue($policy->createAccess('story', 'story', $admin)->isAllowed());
    }

    #[Test]
    public function grants_nothing_to_a_non_admin(): void
    {
        $policy = $this->policy(['story' => 'content']);
        $anon = $this->account(isContentAdmin: false);
        $published = $this->entity(status: true);

        self::assertTrue($policy->access($published, 'view', $anon)->isNeutral());
        self::assertTrue($policy->access($published, 'update', $anon)->isNeutral());
        self::assertTrue($policy->access($published, 'delete', $anon)->isNeutral());
        self::assertTrue($policy->createAccess('story', 'story', $anon)->isNeutral());
    }

    #[Test]
    public function never_returns_forbidden(): void
    {
        // Additive contract: a more specific policy's Forbidden must always win.
        $policy = $this->policy(['story' => 'content']);
        foreach ([$this->account(true), $this->account(false)] as $account) {
            foreach (['view', 'update', 'delete', 'whatever'] as $op) {
                self::assertFalse($policy->access($this->entity(), $op, $account)->isForbidden());
            }
            self::assertFalse($policy->createAccess('story', 'story', $account)->isForbidden());
        }
    }

    #[Test]
    public function has_no_opinion_on_unsupported_operations(): void
    {
        $policy = $this->policy(['story' => 'content']);
        $admin = $this->account(isContentAdmin: true);

        // 'publish' etc. are not in the managed set — Neutral, not Allowed.
        self::assertTrue($policy->access($this->entity(true), 'publish', $admin)->isNeutral());
    }
}
