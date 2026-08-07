<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Policy\PublishedContentAccessPolicy;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Node\Node;
use Waaseyaa\SSR\SsrPageHandler;

/**
 * The canonical entity-level view-access gate for the SSR render path
 * (author-path FR-006; audit M2 / R6 PR2; generalized to every entity type in
 * R8-a).
 *
 * The node-centric {@see \Waaseyaa\Workflows\EditorialVisibilityResolver} only
 * covers publish/preview/workflow state, so a generic `make:content-type`
 * entity would otherwise serve drafts to anonymous visitors via HTML/Markdown
 * even though MCP and JSON:API deny them — and, before R6 PR2, a `node` was
 * EXCLUDED from this gate entirely, so a published-but-access-restricted node
 * (e.g. a classification hold) never ran the entity-level access check at all.
 * {@see SsrPageHandler::shouldDenyEntityRender()} (formerly
 * `shouldDenyContentGroupRender()`) closes both gaps by deferring every
 * entity — `content`-group or not, `node` included — to the SAME per-entity
 * AccessPolicy ({@see PublishedContentAccessPolicy}, `NodeAccessPolicy`,
 * `ClassificationFieldAccessPolicy`, etc.) the other read surfaces use.
 *
 * R8-a: this method used to early-return `false` (never deny) for any entity
 * type outside the `content` group, via a now-removed `isContentGroupEntity()`
 * guard — see {@see never_gates_non_content_group_types_with_a_granting_policy()}
 * and {@see gates_non_content_group_types_with_no_granting_policy()} below for
 * the corrected invariant.
 */
#[CoversClass(SsrPageHandler::class)]
final class SsrContentPublishedGateTest extends TestCase
{
    private function entityTypeManager(): EntityTypeManager
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $etm->registerEntityType(new EntityType(id: 'story', label: 'Story', class: Node::class, group: 'content'));
        $etm->registerEntityType(new EntityType(id: 'profile', label: 'Profile', class: Node::class, group: 'people'));
        $etm->registerEntityType(new EntityType(id: 'node', label: 'Content', class: Node::class, group: 'content'));

        return $etm;
    }

    /**
     * Mirrors ClassificationFieldAccessPolicy's entity-level Forbidden for a
     * legal-hold / insufficient-clearance node: applies to 'node', returns
     * Forbidden for 'view' regardless of account or publish status.
     */
    private function forbidNodeViewHandler(): EntityAccessHandler
    {
        return new EntityAccessHandler([
            new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return $operation === 'view'
                        ? AccessResult::forbidden('Entity is under legal hold.')
                        : AccessResult::neutral();
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'node';
                }

                public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }
            },
        ]);
    }

    private function handler(EntityTypeManager $etm, ?EntityAccessHandler $accessHandler): SsrPageHandler
    {
        $db = DBALDatabase::createSqlite();

        return new SsrPageHandler(
            entityTypeManager: $etm,
            database: $db,
            renderCache: null,
            cacheConfigResolver: new CacheConfigResolver([]),
            discoveryHandler: new DiscoveryApiHandler($etm, $db),
            projectRoot: '/tmp/test-project',
            config: [],
            accessHandler: $accessHandler,
        );
    }

    private function accessHandler(EntityTypeManager $etm): EntityAccessHandler
    {
        $handler = new EntityAccessHandler();
        $handler->addPolicy(new PublishedContentAccessPolicy($etm));

        return $handler;
    }

    /**
     * @param mixed $status The entity's `status` field value.
     */
    private function entity(string $entityTypeId, mixed $status): EntityInterface
    {
        $entity = $this->createStub(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($entityTypeId);
        $entity->method('bundle')->willReturn('');
        $entity->method('get')->willReturnCallback(
            static fn(string $name): mixed => $name === 'status' ? $status : null,
        );

        return $entity;
    }

    private function anon(): AccountInterface
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('isAuthenticated')->willReturn(false);
        $account->method('hasPermission')->willReturn(false);

        return $account;
    }

    private function shouldDeny(SsrPageHandler $handler, string $entityTypeId, EntityInterface $entity, AccountInterface $account): bool
    {
        $method = new \ReflectionMethod(SsrPageHandler::class, 'shouldDenyEntityRender');

        return (bool) $method->invoke($handler, $entityTypeId, $entity, $account);
    }

    #[Test]
    public function allows_anonymous_render_of_published_content(): void
    {
        $etm = $this->entityTypeManager();
        $handler = $this->handler($etm, $this->accessHandler($etm));

        self::assertFalse(
            $this->shouldDeny($handler, 'story', $this->entity('story', true), $this->anon()),
            'a published content-group entity must render for anonymous',
        );
    }

    #[Test]
    public function denies_anonymous_render_of_unpublished_content(): void
    {
        $etm = $this->entityTypeManager();
        $handler = $this->handler($etm, $this->accessHandler($etm));

        self::assertTrue(
            $this->shouldDeny($handler, 'story', $this->entity('story', false), $this->anon()),
            'an unpublished content-group entity must be denied — no HTML/Markdown leak',
        );
    }

    #[Test]
    public function denies_content_with_no_status_signal(): void
    {
        $etm = $this->entityTypeManager();
        $handler = $this->handler($etm, $this->accessHandler($etm));

        self::assertTrue(
            $this->shouldDeny($handler, 'story', $this->entity('story', null), $this->anon()),
            'content with no published signal must not be served to anonymous',
        );
    }

    #[Test]
    public function allows_a_published_node_with_no_restrictive_policy(): void
    {
        // Regression guard: nodes are NO LONGER excluded from this gate (R6
        // PR2), but a standard published node with only PublishedContentAccessPolicy
        // opining must still render — this gate must not over-deny the common case.
        $etm = $this->entityTypeManager();
        $handler = $this->handler($etm, $this->accessHandler($etm));

        self::assertFalse(
            $this->shouldDeny($handler, 'node', $this->entity('node', true), $this->anon()),
            'a published node with no restrictive access policy must still render',
        );
    }

    #[Test]
    public function gates_a_view_forbidden_node(): void
    {
        // Audit M2 / R6 PR2 exploit-closed regression: before the fix, this
        // method opened with `$entityTypeId === 'node' || ...` and returned
        // false unconditionally for nodes, so a published-but-access-restricted
        // node (e.g. a classification hold) NEVER ran the entity-level
        // accessHandler->check() gate and rendered 200 to anonymous. Nodes now
        // go through the same per-entity AccessPolicy check as any other
        // content-group type.
        $etm = $this->entityTypeManager();
        $handler = $this->handler($etm, $this->forbidNodeViewHandler());

        self::assertTrue(
            $this->shouldDeny($handler, 'node', $this->entity('node', true), $this->anon()),
            'a published node forbidden by entity-level access policy must be gated here — it was previously excluded',
        );
        self::assertTrue(
            $this->shouldDeny($handler, 'node', $this->entity('node', false), $this->anon()),
            'an unpublished, view-forbidden node must also be gated',
        );
    }

    #[Test]
    public function gates_non_content_group_types_with_no_granting_policy(): void
    {
        // R8-a: before the fix, this method opened with
        // `if (!$this->isContentGroupEntity($entityTypeId)) { return false; }`,
        // so ANY non-content-group type (e.g. `profile`, here standing in for
        // `oidc_client`) was NEVER gated regardless of what its AccessPolicy
        // said — a whole-entity disclosure once an alias to it existed. The
        // `content`-group-only `PublishedContentAccessPolicy` has no opinion on
        // `profile`, so with no other policy granting view, the generalized
        // gate now correctly denies it (deny-by-default, matching every other
        // read surface).
        $etm = $this->entityTypeManager();
        $handler = $this->handler($etm, $this->accessHandler($etm));

        self::assertTrue(
            $this->shouldDeny($handler, 'profile', $this->entity('profile', true), $this->anon()),
            'a non-content-group type with no policy granting view must be gated — it was previously excluded entirely',
        );
        self::assertTrue(
            $this->shouldDeny($handler, 'profile', $this->entity('profile', false), $this->anon()),
            'an unpublished non-content-group type must also be gated',
        );
    }

    #[Test]
    public function never_gates_non_content_group_types_with_a_granting_policy(): void
    {
        // Regression guard companion: a non-content-group type IS still
        // rendered when SOME policy actively grants 'view' for it (mirrors
        // MediaAccessPolicy/TermAccessPolicy/UserAccessPolicy's shape — an
        // explicit grant, not a free pass from group membership).
        $etm = $this->entityTypeManager();
        $handler = new EntityAccessHandler([
            new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return $operation === 'view'
                        ? AccessResult::allowed('Profiles are publicly viewable in this test policy.')
                        : AccessResult::neutral();
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'profile';
                }

                public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }
            },
        ]);

        self::assertFalse(
            $this->shouldDeny($this->handler($etm, $handler), 'profile', $this->entity('profile', true), $this->anon()),
            'a non-content-group type must still render when its own AccessPolicy actively grants view',
        );
    }

    #[Test]
    public function fails_closed_when_no_access_handler_is_wired(): void
    {
        // A wiring gap must deny content renders rather than risk leaking a draft.
        $etm = $this->entityTypeManager();
        $handler = $this->handler($etm, null);

        self::assertTrue(
            $this->shouldDeny($handler, 'story', $this->entity('story', true), $this->anon()),
            'no handler => content render denied (fail closed)',
        );
    }
}
