<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Policy\PublishedContentAccessPolicy;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

#[CoversClass(PublishedContentAccessPolicy::class)]
final class PublishedContentAccessPolicyTest extends TestCase
{
    /**
     * @param array<string, string> $groups Entity type id => group.
     */
    private function policy(array $groups): PublishedContentAccessPolicy
    {
        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturnCallback(static fn(string $id): bool => isset($groups[$id]));
        $etm->method('getDefinition')->willReturnCallback(function (string $id) use ($groups): EntityTypeInterface {
            $type = $this->createMock(EntityTypeInterface::class);
            $type->method('getGroup')->willReturn($groups[$id] ?? null);

            return $type;
        });

        return new PublishedContentAccessPolicy($etm);
    }

    private function entity(mixed $status): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('get')->willReturnCallback(static fn(string $name): mixed => $name === 'status' ? $status : null);

        return $entity;
    }

    private function anon(): AccountInterface
    {
        return $this->createMock(AccountInterface::class);
    }

    #[Test]
    public function applies_only_to_the_content_group(): void
    {
        $policy = $this->policy(['story' => 'content', 'user' => 'people', 'term' => 'taxonomy']);

        self::assertTrue($policy->appliesTo('story'));
        self::assertFalse($policy->appliesTo('user'), 'must not apply to people group (user.status = active, not published)');
        self::assertFalse($policy->appliesTo('term'));
        self::assertFalse($policy->appliesTo('ghost'), 'unknown type does not apply');
    }

    #[Test]
    public function grants_anonymous_view_of_published_content(): void
    {
        $result = $this->policy(['story' => 'content'])->access($this->entity(true), 'view', $this->anon());
        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function does_not_grant_view_of_unpublished_content(): void
    {
        $result = $this->policy(['story' => 'content'])->access($this->entity(false), 'view', $this->anon());
        self::assertTrue($result->isNeutral());
        self::assertFalse($result->isAllowed());
    }

    #[Test]
    public function does_not_grant_view_of_content_with_no_status_signal(): void
    {
        $result = $this->policy(['story' => 'content'])->access($this->entity(null), 'view', $this->anon());
        self::assertTrue($result->isNeutral());
    }

    #[Test]
    public function has_no_opinion_on_write_operations(): void
    {
        $policy = $this->policy(['story' => 'content']);
        $published = $this->entity(true);

        self::assertTrue($policy->access($published, 'update', $this->anon())->isNeutral());
        self::assertTrue($policy->access($published, 'delete', $this->anon())->isNeutral());
        self::assertTrue($policy->createAccess('story', 'story', $this->anon())->isNeutral());
    }

    #[Test]
    public function never_returns_forbidden(): void
    {
        // Additive contract: a more specific policy's Forbidden must always win,
        // so this policy must never itself forbid.
        $policy = $this->policy(['story' => 'content']);
        foreach (['view', 'update', 'delete', 'whatever'] as $op) {
            self::assertFalse($policy->access($this->entity(true), $op, $this->anon())->isForbidden());
            self::assertFalse($policy->access($this->entity(false), $op, $this->anon())->isForbidden());
        }
    }
}
