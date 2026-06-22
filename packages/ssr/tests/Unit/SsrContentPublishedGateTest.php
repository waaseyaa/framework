<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Policy\PublishedContentAccessPolicy;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Cache\CacheConfigResolver;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Node\Node;
use Waaseyaa\SSR\SsrPageHandler;

/**
 * The canonical published gate for the SSR render path (author-path FR-006).
 *
 * The node-centric {@see \Waaseyaa\Workflows\EditorialVisibilityResolver} allows
 * any non-`node` type outright, so a generic `make:content-type` entity would
 * otherwise serve drafts to anonymous visitors via HTML/Markdown even though MCP
 * and JSON:API deny them. {@see SsrPageHandler::shouldDenyContentGroupRender()}
 * closes that gap by deferring to the SAME per-entity AccessPolicy
 * ({@see PublishedContentAccessPolicy}) the other read surfaces use.
 */
#[CoversClass(SsrPageHandler::class)]
final class SsrContentPublishedGateTest extends TestCase
{
    private function entityTypeManager(): EntityTypeManager
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $etm->registerEntityType(new EntityType(id: 'story', label: 'Story', class: Node::class, group: 'content'));
        $etm->registerEntityType(new EntityType(id: 'profile', label: 'Profile', class: Node::class, group: 'people'));

        return $etm;
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
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($entityTypeId);
        $entity->method('bundle')->willReturn('');
        $entity->method('get')->willReturnCallback(
            static fn(string $name): mixed => $name === 'status' ? $status : null,
        );

        return $entity;
    }

    private function anon(): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('isAuthenticated')->willReturn(false);
        $account->method('hasPermission')->willReturn(false);

        return $account;
    }

    private function shouldDeny(SsrPageHandler $handler, string $entityTypeId, EntityInterface $entity, AccountInterface $account): bool
    {
        $method = new \ReflectionMethod(SsrPageHandler::class, 'shouldDenyContentGroupRender');

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
    public function never_gates_nodes_here(): void
    {
        // Nodes keep their published/preview/workflow nuance in the editorial
        // resolver; this gate must be a no-op for them regardless of status.
        $etm = $this->entityTypeManager();
        $handler = $this->handler($etm, $this->accessHandler($etm));

        self::assertFalse($this->shouldDeny($handler, 'node', $this->entity('node', false), $this->anon()));
        self::assertFalse($this->shouldDeny($handler, 'node', $this->entity('node', true), $this->anon()));
    }

    #[Test]
    public function never_gates_non_content_group_types(): void
    {
        // People/taxonomy/etc. keep their existing visibility behavior.
        $etm = $this->entityTypeManager();
        $handler = $this->handler($etm, $this->accessHandler($etm));

        self::assertFalse(
            $this->shouldDeny($handler, 'profile', $this->entity('profile', false), $this->anon()),
            'non-content-group types are out of scope for the published gate',
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
