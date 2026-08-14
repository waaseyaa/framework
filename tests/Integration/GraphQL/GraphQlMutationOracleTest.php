<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\GraphQL\GraphQlEndpoint;
use Waaseyaa\Tests\Integration\GraphQL\Policy\FieldOraclePolicy;
use Waaseyaa\Tests\Integration\GraphQL\Policy\MutationOraclePolicy;

/**
 * R11 (audit A9): `/graphql` is registered `allowAll()` (anonymous-reachable,
 * `GraphQlRouteProvider`) and executes mutations for the anonymous account. The
 * generated `update{Type}`/`delete{Type}` resolvers threw DISTINGUISHABLE errors:
 * `EntityResolver::resolveUpdate()`/`resolveDelete()` throw "Entity not found:
 * {type}/{id}" for an absent id, but `GraphQlAccessGuard` throws "Access denied:
 * cannot update/delete entity" for a real entity the caller cannot modify, so an
 * ANONYMOUS caller could enumerate the existence of any entity id despite every
 * per-entity access policy being correct.
 *
 * These tests are the exploit: confirmed RED against pre-fix code (distinguishable
 * messages), GREEN after the two-layer fix (endpoint anonymous-mutation gate +
 * resolver not-found/forbidden collapse).
 */
final class GraphQlMutationOracleTest extends GraphQlIntegrationTestBase
{
    private GraphQlEndpoint $anonymousEndpoint;
    private GraphQlEndpoint $memberEndpoint;

    protected function setUp(): void
    {
        parent::setUp();

        // Replace the base harness's AllowAllPolicy (which would grant update/delete
        // to anonymous too, masking the oracle) with a policy that opens `view` to
        // everyone but restricts `update`/`delete` to admins -- the shape needed to
        // isolate the mutation-only oracle from the read path.
        $this->accessHandler = new EntityAccessHandler([new MutationOraclePolicy()]);
        $handler = $this->accessHandler;

        $this->anonymousEndpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $handler,
            $this->createAccount(0, ['anonymous']),
        );

        $this->memberEndpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $handler,
            $this->createAccount(2, ['authenticated', 'member']),
        );
    }

    private function requestAs(GraphQlEndpoint $endpoint, string $graphql): array
    {
        $body = json_encode(['query' => $graphql], JSON_THROW_ON_ERROR);
        return $endpoint->handle('POST', $body)['body'];
    }

    /**
     * Endpoint for an AUTHENTICATED non-admin editor whose entity-level `update`
     * IS allowed but whose edit of the `body` field is FORBIDDEN (FieldOraclePolicy,
     * shaped like the real NodeAccessPolicy). Reassigns $this->accessHandler so the
     * whole stack (endpoint guard + query-layer resolver) reads one handler.
     */
    private function fieldOracleEndpoint(): GraphQlEndpoint
    {
        // Forbid editing the author `secret` field -- a plain-string field, so the
        // generated update input takes a scalar (a `text`-typed field like article
        // `body` would require a structured TextValueInput and fail schema
        // validation before the resolver, never reaching the field-access check).
        $this->accessHandler = new EntityAccessHandler([new FieldOraclePolicy('secret')]);

        return new GraphQlEndpoint(
            $this->entityTypeManager,
            $this->accessHandler,
            $this->createAccount(3, ['authenticated', 'editor']),
        );
    }

    /** @param array<string, mixed> $response */
    private function firstErrorMessage(array $response): string
    {
        return $response['errors'][0]['message'] ?? '';
    }

    // ── 1. ANONYMOUS ORACLE (the primary defect) ─────────────────

    public function testAnonymousUpdateOracleIsClosed(): void
    {
        // Article id 1 exists (seeded by the base harness, MutationOraclePolicy
        // forbids anonymous from updating it); id 999 does not exist at all.
        $absent = $this->requestAs($this->anonymousEndpoint, '
            mutation { updateArticle(id: "999", input: { title: "X" }) { title } }
        ');
        $forbidden = $this->requestAs($this->anonymousEndpoint, '
            mutation { updateArticle(id: "1", input: { title: "X" }) { title } }
        ');

        $this->assertArrayHasKey('errors', $absent, 'A nonexistent id must error.');
        $this->assertArrayHasKey('errors', $forbidden, 'Anonymous cannot update -- the real id must also error.');

        $absentMessage = $this->firstErrorMessage($absent);
        $forbiddenMessage = $this->firstErrorMessage($forbidden);

        // Post-fix: anonymous mutations are rejected uniformly BEFORE the resolver
        // ever loads an entity -- identical error text, no entity id or type leaked.
        $this->assertSame(
            $absentMessage,
            $forbiddenMessage,
            'Anonymous update on an absent id vs a real-but-forbidden id must be indistinguishable.',
        );
        $this->assertStringNotContainsString('999', $absentMessage);
        $this->assertStringNotContainsString('article', strtolower($absentMessage));
    }

    public function testAnonymousDeleteOracleIsClosed(): void
    {
        $absent = $this->requestAs($this->anonymousEndpoint, '
            mutation { deleteArticle(id: "999") { deleted } }
        ');
        $forbidden = $this->requestAs($this->anonymousEndpoint, '
            mutation { deleteArticle(id: "1") { deleted } }
        ');

        $this->assertArrayHasKey('errors', $absent);
        $this->assertArrayHasKey('errors', $forbidden);
        $this->assertSame(
            $this->firstErrorMessage($absent),
            $this->firstErrorMessage($forbidden),
            'Anonymous delete on an absent id vs a real-but-forbidden id must be indistinguishable.',
        );
    }

    // ── 2. AUTHENTICATED RESIDUAL ─────────────────────────────────

    public function testAuthenticatedLowPrivilegeUpdateOracleIsClosed(): void
    {
        $token = $this->mutationToken('article', 1);
        // The 'member' account is authenticated but not an admin -- MutationOraclePolicy
        // forbids it from updating. Authenticated mutations are NOT blocked by the
        // endpoint-level gate (that only rejects anonymous), so this exercises the
        // resolver-level collapse (STEP 2b) in isolation.
        $absent = $this->requestAs($this->memberEndpoint, "
            mutation { updateArticle(id: \"999\", input: { title: \"X\", mutationToken: \"{$token}\" }) { title } }
        ");
        $forbidden = $this->requestAs($this->memberEndpoint, "
            mutation { updateArticle(id: \"1\", input: { title: \"X\", mutationToken: \"{$token}\" }) { title } }
        ");

        $this->assertArrayHasKey('errors', $absent);
        $this->assertArrayHasKey('errors', $forbidden);

        $absentMessage = $this->firstErrorMessage($absent);
        $forbiddenMessage = $this->firstErrorMessage($forbidden);

        // The messages echo back the caller-supplied id (999 vs 1) so they are not
        // byte-identical -- that leaks nothing new, since the caller already knows
        // which id it asked about. What must be indistinguishable is the WORDING:
        // pre-fix, an absent id said "Entity not found" while a real-but-forbidden
        // id said "Access denied: cannot update entity" -- a wording diff an
        // attacker could use to enumerate existence regardless of the id echoed.
        $this->assertStringStartsWith('Entity not found:', $absentMessage);
        $this->assertStringStartsWith(
            'Entity not found:',
            $forbiddenMessage,
            'Authenticated non-privileged update on a real-but-forbidden id must read as not-found, not access-denied.',
        );
        $this->assertStringNotContainsString('denied', strtolower($forbiddenMessage));
    }

    public function testAuthenticatedLowPrivilegeDeleteOracleIsClosed(): void
    {
        $token = $this->mutationToken('article', 1);
        $absent = $this->requestAs($this->memberEndpoint, "
            mutation { deleteArticle(id: \"999\", mutationToken: \"{$token}\") { deleted } }
        ");
        $forbidden = $this->requestAs($this->memberEndpoint, "
            mutation { deleteArticle(id: \"1\", mutationToken: \"{$token}\") { deleted } }
        ");

        $this->assertArrayHasKey('errors', $absent);
        $this->assertArrayHasKey('errors', $forbidden);

        $absentMessage = $this->firstErrorMessage($absent);
        $forbiddenMessage = $this->firstErrorMessage($forbidden);

        $this->assertStringStartsWith('Entity not found:', $absentMessage);
        $this->assertStringStartsWith(
            'Entity not found:',
            $forbiddenMessage,
            'Authenticated non-privileged delete on a real-but-forbidden id must read as not-found, not access-denied.',
        );
        $this->assertStringNotContainsString('denied', strtolower($forbiddenMessage));
    }

    // ── 2b. FIELD-LEVEL RESIDUAL (R11 follow-up) ─────────────────

    public function testAuthenticatedFieldEditOracleIsClosed(): void
    {
        // The 'editor' account (authenticated, non-admin) passes the ENTITY-level
        // update check (FieldOraclePolicy allows it) but is FORBIDDEN from editing
        // the `secret` field. Pre-fix the field-edit denial ("Access denied: cannot
        // edit field 'secret'") fired only for a REAL entity -- the absent branch
        // returned "Entity not found" earlier -- so it distinguished absent from
        // real for any editor, over every id. This is the residual the field-edit
        // loop moving inside the not-found collapse must close.
        $endpoint = $this->fieldOracleEndpoint();
        $token = $this->mutationToken('author', 1);

        $absent = $this->requestAs($endpoint, "
            mutation { updateAuthor(id: \"999\", input: { secret: \"X\", mutationToken: \"{$token}\" }) { name } }
        ");
        $forbidden = $this->requestAs($endpoint, "
            mutation { updateAuthor(id: \"1\", input: { secret: \"X\", mutationToken: \"{$token}\" }) { name } }
        ");

        $this->assertArrayHasKey('errors', $absent);
        $this->assertArrayHasKey('errors', $forbidden, 'The editor cannot edit secret -- a real id must also error.');

        $absentMessage = $this->firstErrorMessage($absent);
        $forbiddenMessage = $this->firstErrorMessage($forbidden);

        $this->assertStringStartsWith('Entity not found:', $absentMessage);
        $this->assertStringStartsWith(
            'Entity not found:',
            $forbiddenMessage,
            'A forbidden-field edit on a real id must read as not-found, not "cannot edit field".',
        );
        $this->assertStringNotContainsString('edit field', strtolower($forbiddenMessage));
        $this->assertStringNotContainsString('denied', strtolower($forbiddenMessage));
    }

    public function testEditorCanStillUpdateAnAllowedField(): void
    {
        // Positive control: the field-edit access check must still RUN (not be
        // skipped) -- editing an ALLOWED field (name is Neutral -> permitted)
        // by the same editor succeeds and returns the new value, proving the
        // not-found collapse only fires on an actual denial and does not
        // over-block legitimate edits.
        $endpoint = $this->fieldOracleEndpoint();
        $token = $this->mutationToken('author', 1);

        $result = $this->requestAs($endpoint, "
            mutation { updateAuthor(id: \"1\", input: { name: \"Renamed\", mutationToken: \"{$token}\" }) { name } }
        ");

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertSame('Renamed', $result['data']['updateAuthor']['name']);
    }

    // ── 3. CONTROL: anonymous public reads must not regress ──────

    public function testAnonymousCanStillReadPublicContent(): void
    {
        // MutationOraclePolicy allows `view` unconditionally -- the endpoint-level
        // anonymous-mutation gate must not touch queries at all.
        $result = $this->requestAs($this->anonymousEndpoint, '
            { article(id: "1") { title } }
        ');

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertSame('Hello', $result['data']['article']['title']);
    }
}
