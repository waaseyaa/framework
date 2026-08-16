<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\GraphQL\GraphQlEndpoint;
use Waaseyaa\Tests\Integration\GraphQL\Policy\RoleBasedPolicy;

/**
 * R12 (audit A10, SECURITY): SchemaFactory's static per-process schema cache
 * (SchemaFactory::$schemaCache) is keyed only by entity-type ids + mutation
 * override keys, NOT by account. GraphQlEndpoint::handle() builds a fresh
 * per-request GraphQlAccessGuard/EntityResolver/ReferenceLoader bound to the
 * request account on every call, but SchemaFactory::build() returns the
 * CACHED Schema on a cache hit, and that cached Schema's resolver closures
 * captured the FIRST request's entityResolver/referenceLoader (bound to the
 * first request's account). Under FrankenPHP worker mode (the documented
 * production runtime), a single process serves many requests without
 * process teardown, so every request after the first one to build a given
 * schema shape (same entity types + mutation overrides) executes queries
 * AND mutations under the FIRST request's account, defeating every
 * per-entity access policy.
 *
 * These tests simulate that worker-mode reuse explicitly. They do NOT rely
 * on GraphQlIntegrationTestBase::setUp()'s SchemaFactory::resetCache() call
 * to isolate the bug: that call only provides the "fresh process" baseline
 * once per TEST METHOD. The bug requires two DIFFERENT accounts to hit the
 * SAME cached schema inside a single test method with no reset in between,
 * exactly like two sequential worker-mode requests reusing one process.
 * Confirmed RED against pre-fix code (account B inherits account A's
 * access); GREEN after moving the per-request collaborators into the
 * GraphQL execution context (see GraphQlExecutionContext).
 */
final class GraphQlSchemaCacheCrossAccountBleedTest extends GraphQlIntegrationTestBase
{
    /** @return array<string, mixed> */
    private function requestAs(GraphQlEndpoint $endpoint, string $graphql): array
    {
        $body = json_encode(['query' => $graphql], JSON_THROW_ON_ERROR);

        return $endpoint->handle('POST', $body)['body'];
    }

    /**
     * Query-side bleed: account A (admin, view allowed) primes the schema
     * cache; account B (anonymous, view forbidden by RoleBasedPolicy) reuses
     * the SAME cached schema with no resetCache in between. Secure
     * expectation: anonymous gets null (view denied). Pre-fix, the cached
     * schema's query resolver still calls account A's entityResolver, so the
     * anonymous request leaks account A's view access and returns the entity.
     */
    public function testCachedSchemaQueryDoesNotLeakFirstRequestAccountAccess(): void
    {
        $this->accessHandler = new EntityAccessHandler([new RoleBasedPolicy()]);
        $handler = $this->accessHandler;

        $adminEndpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $handler,
            $this->createAccount(1, ['admin', 'authenticated']),
        );
        $anonymousEndpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $handler,
            $this->createAccount(0, ['anonymous']),
        );

        // Request A: admin primes the static schema cache. Its resolver
        // closures capture THIS request's entityResolver/referenceLoader.
        $adminResponse = $this->requestAs($adminEndpoint, '{ article(id: "1") { title } }');
        $this->assertNoErrors($adminResponse);
        $this->assertSame('Hello', $adminResponse['data']['article']['title']);

        // Request B: anonymous, SAME schema cache (no resetCache call).
        // RoleBasedPolicy forbids anonymous `view` entirely, so the entity
        // must be absent for B.
        $anonymousResponse = $this->requestAs($anonymousEndpoint, '{ article(id: "1") { title } }');
        $this->assertNoErrors($anonymousResponse);
        $this->assertNull(
            $anonymousResponse['data']['article'],
            'Anonymous reused the cached schema built for the admin request and inherited admin view access '
            . '(the schema cache is keyed without account, and its resolver closures captured the first '
            . "request's account-bound EntityResolver).",
        );
    }

    /**
     * Mutation-side bleed: account A (admin, update allowed) primes the
     * cache with a real update; account B (member, update forbidden by
     * RoleBasedPolicy) reuses the SAME cached schema and attempts to update a
     * DIFFERENT entity it is not permitted to touch. Secure expectation:
     * R11's collapsed "Entity not found" error (member cannot distinguish
     * absent from forbidden). Pre-fix, the cached schema's mutation resolver
     * still calls account A's entityResolver, so member's forbidden update
     * SUCCEEDS under admin's access.
     */
    public function testCachedSchemaMutationDoesNotLeakFirstRequestAccountAccess(): void
    {
        $this->accessHandler = new EntityAccessHandler([new RoleBasedPolicy()]);
        $handler = $this->accessHandler;

        $adminEndpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $handler,
            $this->createAccount(1, ['admin', 'authenticated']),
        );
        $memberEndpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $handler,
            $this->createAccount(2, ['authenticated', 'member']),
        );
        $articleOneToken = $this->mutationToken('article', 1);
        $articleTwoToken = $this->mutationToken('article', 2);

        // Request A: admin primes the static schema cache with a real,
        // permitted update on article 1.
        $adminResponse = $this->requestAs($adminEndpoint, "
            mutation { updateArticle(id: \"1\", input: { title: \"Renamed by admin\", mutationToken: \"{$articleOneToken}\" }) { title } }
        ");
        $this->assertNoErrors($adminResponse);
        $this->assertSame('Renamed by admin', $adminResponse['data']['updateArticle']['title']);

        // Request B: member, SAME schema cache (no resetCache call), attempts
        // to update a DIFFERENT article (id 2) it is NOT permitted to touch.
        // RoleBasedPolicy forbids non-admin update; R11 collapses this to
        // "Entity not found" so member cannot distinguish forbidden from
        // absent.
        $memberResponse = $this->requestAs($memberEndpoint, "
            mutation { updateArticle(id: \"2\", input: { title: \"Hacked by member\", mutationToken: \"{$articleTwoToken}\" }) { title } }
        ");

        $this->assertArrayHasKey(
            'errors',
            $memberResponse,
            'Member reused the cached schema built for the admin request and inherited admin update access '
            . '(the schema cache is keyed without account, and its resolver closures captured the first '
            . "request's account-bound EntityResolver).",
        );
        $this->assertStringStartsWith(
            'Entity not found:',
            $memberResponse['errors'][0]['message'] ?? '',
        );

        // Defense in depth: even if the error assertion above were bypassed,
        // the data must not have changed.
        $verify = $this->requestAs($adminEndpoint, '{ article(id: "2") { title } }');
        $this->assertNoErrors($verify);
        $this->assertSame('World', $verify['data']['article']['title']);
    }
}
