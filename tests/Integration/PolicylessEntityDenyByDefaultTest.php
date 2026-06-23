<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\User;

/**
 * Defense-in-depth pin for the deny-by-default contract on POLICY-LESS entity
 * types over the JSON:API surface.
 *
 * An entity type with NO registered AccessPolicy resolves to `Neutral` for every
 * `EntityAccessHandler::check()`. Deny-by-default means Neutral is NOT a grant, so
 * on the access-checked path (an account bound — every HTTP request carries at
 * least an AnonymousUser via BearerAuthMiddleware) a policy-less entity must be
 * INVISIBLE: empty collection, total 0, 404 on show. This is the structural
 * protection that kept the `group`/`workspace`/etc. packages safe even though
 * they ship no policy (unlike the WP14 messaging defect, which had a public read
 * surface). It rests entirely on `JsonApiController` running the `isAllowed()`
 * gate, which it does only when BOTH an account and an access handler are wired.
 *
 * These assertions lock that contract so a future refactor of the
 * account/handler wiring cannot quietly make every policy-less entity type
 * world-readable. The ONLY unfiltered path is the explicit account-less
 * system-context construction (`new JsonApiController($mgr, $serializer)` — no
 * handler, no account → `accessCheck(false)`), which is reserved for internal
 * tooling and must NEVER front an HTTP surface; the final test documents that
 * boundary so the bypass stays a deliberate, visible choice.
 */
#[CoversNothing]
final class PolicylessEntityDenyByDefaultTest extends TestCase
{
    private InMemoryEntityStorage $storage;

    private EntityTypeManager $entityTypeManager;

    /** A handler with NO policy for the 'widget' type — every check is Neutral. */
    private EntityAccessHandler $emptyHandler;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('widget');
        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
        );
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'widget',
            label: 'Widget',
            class: \Waaseyaa\Api\Tests\Fixtures\TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        ));

        // No policies at all — the structural "policy-less entity type" case.
        $this->emptyHandler = new EntityAccessHandler([]);

        $this->storage->save($this->storage->create(['title' => 'widget-one']));
        $this->storage->save($this->storage->create(['title' => 'widget-two']));
    }

    #[Test]
    public function anonymous_index_of_a_policyless_entity_is_empty_and_total_zero(): void
    {
        $doc = $this->buildController(new AnonymousUser())->index('widget');

        self::assertSame(200, $doc->statusCode);
        $array = $doc->toArray();
        self::assertCount(0, $array['data'], 'A policy-less entity type must expose no rows to an access-checked caller (deny-by-default).');
        self::assertSame(0, $array['meta']['total'] ?? null, 'meta.total must reflect the access-filtered (zero) count, not the raw row count.');
    }

    #[Test]
    public function anonymous_show_of_a_policyless_entity_is_not_found(): void
    {
        $doc = $this->buildController(new AnonymousUser())->show('widget', 1);

        // Denied view returns the not-found shape (never 403) so it cannot act
        // as an existence oracle.
        self::assertSame(404, $doc->statusCode);
    }

    #[Test]
    public function even_an_authenticated_account_is_denied_a_policyless_entity(): void
    {
        // No policy grants view to ANYONE; deny-by-default means an ordinary
        // authenticated account is denied too (only an explicit Allow grants).
        $account = new User(['uid' => 7, 'name' => 'someone', 'roles' => ['authenticated']]);

        $index = $this->buildController($account)->index('widget');
        self::assertCount(0, $index->toArray()['data']);

        $show = $this->buildController($account)->show('widget', 1);
        self::assertSame(404, $show->statusCode);
    }

    #[Test]
    public function only_the_explicit_accountless_system_construction_is_unfiltered(): void
    {
        // Documents the boundary: a controller built with NO account and NO
        // handler is the deliberate system-context bypass (accessCheck(false)).
        // It returns rows for a policy-less type — which is exactly why this
        // construction must never front an HTTP surface. If an HTTP path ever
        // constructs JsonApiController this way, the deny-by-default pins above
        // are bypassed and policy-less entities become world-readable.
        $systemController = new JsonApiController(
            $this->entityTypeManager,
            new ResourceSerializer($this->entityTypeManager),
        );

        $doc = $systemController->index('widget');
        self::assertSame(200, $doc->statusCode);
        self::assertCount(2, $doc->toArray()['data'], 'The account-less system construction is intentionally unfiltered (accessCheck(false)).');
    }

    private function buildController(User|AnonymousUser $account): JsonApiController
    {
        return new JsonApiController(
            $this->entityTypeManager,
            new ResourceSerializer($this->entityTypeManager),
            $this->emptyHandler,
            $account,
        );
    }
}
