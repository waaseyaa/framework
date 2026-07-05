<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GraphQL\GraphQlEndpoint;
use Waaseyaa\GraphQL\Tests\Fixtures\AttributeFirstEntities\PageSchemaFixture;
use Symfony\Component\EventDispatcher\EventDispatcher;

require_once __DIR__ . '/../Fixtures/AttributeFirstEntities/PageSchemaFixture.php';

#[CoversClass(GraphQlEndpoint::class)]
final class GraphQlEndpointTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private GraphQlEndpoint $endpoint;

    protected function setUp(): void
    {
        EntityType::clearFromClassCache();
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->entityTypeManager->registerCoreEntityType(EntityType::fromClass(PageSchemaFixture::class));

        // Authenticated user (id !== 0) by default.
        $authenticatedAccount = $this->createStub(AccountInterface::class);
        $authenticatedAccount->method('id')->willReturn(1);
        $authenticatedAccount->method('isAuthenticated')->willReturn(true);

        $this->endpoint = new GraphQlEndpoint(
            entityTypeManager: $this->entityTypeManager,
            accessHandler: new EntityAccessHandler([]),
            account: $authenticatedAccount,
        );
    }

    private function createEndpointWithAccount(AccountInterface $account): GraphQlEndpoint
    {
        return new GraphQlEndpoint(
            entityTypeManager: $this->entityTypeManager,
            accessHandler: new EntityAccessHandler([]),
            account: $account,
        );
    }

    #[Test]
    public function handleReturnsIntrospectionResult(): void
    {
        $result = $this->endpoint->handle(
            'POST',
            json_encode(['query' => '{ __schema { queryType { name } } }']),
        );

        self::assertSame(200, $result['statusCode']);
        self::assertSame('Query', $result['body']['data']['__schema']['queryType']['name']);
        self::assertArrayNotHasKey('errors', $result['body']);
    }

    #[Test]
    public function handleGetRequest(): void
    {
        $result = $this->endpoint->handle(
            'GET',
            '',
            ['query' => '{ __typename }'],
        );

        self::assertSame(200, $result['statusCode']);
        self::assertSame('Query', $result['body']['data']['__typename']);
    }

    #[Test]
    public function handleReturns400OnMissingQuery(): void
    {
        $result = $this->endpoint->handle('POST', json_encode([]));

        self::assertSame(400, $result['statusCode']);
        self::assertSame('Missing query', $result['body']['errors'][0]['message']);
    }

    #[Test]
    public function handleReturns400OnEmptyBody(): void
    {
        $result = $this->endpoint->handle('POST', '');

        self::assertSame(400, $result['statusCode']);
    }

    #[Test]
    public function handleReturns400OnInvalidJson(): void
    {
        $result = $this->endpoint->handle('POST', '{invalid json}');

        self::assertSame(400, $result['statusCode']);
        self::assertStringContainsString('Invalid JSON', $result['body']['errors'][0]['message']);
    }

    #[Test]
    public function handleReturnsEntityTypeFields(): void
    {
        $result = $this->endpoint->handle(
            'POST',
            json_encode(['query' => '{ __type(name: "Page") { fields { name } } }']),
        );

        self::assertSame(200, $result['statusCode']);
        $fieldNames = array_column($result['body']['data']['__type']['fields'], 'name');
        self::assertContains('id', $fieldNames);
        self::assertContains('title', $fieldNames);
        self::assertContains('body', $fieldNames);
    }

    #[Test]
    public function handleSupportsVariables(): void
    {
        $result = $this->endpoint->handle(
            'POST',
            json_encode([
                'query' => 'query GetPage($id: ID!) { page(id: $id) { id } }',
                'variables' => ['id' => '1'],
            ]),
        );

        self::assertSame(200, $result['statusCode']);
        // Page won't exist (no storage), so data.page should be null
        self::assertNull($result['body']['data']['page']);
    }

    #[Test]
    public function anonymousUserCannotIntrospectSchema(): void
    {
        $anonymousAccount = $this->createStub(AccountInterface::class);
        $anonymousAccount->method('id')->willReturn(0);
        $endpoint = $this->createEndpointWithAccount($anonymousAccount);

        $result = $endpoint->handle(
            'POST',
            json_encode(['query' => '{ __schema { queryType { name } } }']),
        );

        self::assertSame(200, $result['statusCode']);
        self::assertNotEmpty($result['body']['errors']);
        self::assertStringContainsString(
            'introspection',
            strtolower($result['body']['errors'][0]['message']),
        );
    }

    #[Test]
    public function authenticatedUserCanIntrospectSchema(): void
    {
        $result = $this->endpoint->handle(
            'POST',
            json_encode(['query' => '{ __schema { queryType { name } } }']),
        );

        self::assertSame(200, $result['statusCode']);
        self::assertSame('Query', $result['body']['data']['__schema']['queryType']['name']);
        self::assertArrayNotHasKey('errors', $result['body']);
    }

    // --- WP03: GraphQL-over-HTTP GET is query-only (CSRF: no mutations via GET) ---

    #[Test]
    public function getMutationIsRejectedAndNotExecuted(): void
    {
        // A state-changing mutation reached over GET (`GET /graphql?query=mutation{...}`)
        // is a CSRF vector: a cross-site <img>/<script> drive-by carries the victim's
        // session cookie on a GET. The mutation must be rejected before execution.
        $result = $this->endpoint->handle(
            'GET',
            '',
            ['query' => 'mutation { deletePage(id: "1") { deleted } }'],
        );

        self::assertSame(405, $result['statusCode'], 'GET mutation must be rejected (405), not executed.');
        self::assertArrayNotHasKey('data', $result['body'], 'The mutation must not execute on GET.');
        self::assertNotEmpty($result['body']['errors']);
        self::assertStringContainsString('GET', $result['body']['errors'][0]['message']);
    }

    #[Test]
    public function postMutationIsStillAllowed(): void
    {
        // The fix is GET-only: POST mutations remain reachable (no BC break).
        // (No storage is wired, so the resolver may error — but the request is
        // PROCESSED as a mutation, i.e. not blocked with 405 by the GET guard.)
        $result = $this->endpoint->handle(
            'POST',
            json_encode(['query' => 'mutation { deletePage(id: "1") { deleted } }']),
        );

        self::assertNotSame(405, $result['statusCode'], 'POST mutations must not be blocked by the GET guard.');
        self::assertSame(200, $result['statusCode']);
    }

    #[Test]
    public function getQueryIsStillAllowed(): void
    {
        // Queries over GET are legitimate (cacheable) and must keep working.
        $result = $this->endpoint->handle('GET', '', ['query' => '{ __typename }']);

        self::assertSame(200, $result['statusCode']);
        self::assertSame('Query', $result['body']['data']['__typename']);
    }

    // --- R11 (audit A9): anonymous-mutation gate closes the existence oracle ---

    #[Test]
    public function anonymousPostMutationIsRejectedBeforeExecution(): void
    {
        $anonymousAccount = $this->createStub(AccountInterface::class);
        $anonymousAccount->method('id')->willReturn(0);
        $anonymousAccount->method('isAuthenticated')->willReturn(false);
        $endpoint = $this->createEndpointWithAccount($anonymousAccount);

        $result = $endpoint->handle(
            'POST',
            json_encode(['query' => 'mutation { deletePage(id: "1") { deleted } }']),
        );

        self::assertSame(401, $result['statusCode']);
        self::assertArrayNotHasKey('data', $result['body'], 'The mutation must not execute for an anonymous caller.');
        self::assertNotEmpty($result['body']['errors']);
        self::assertStringNotContainsString('1', $result['body']['errors'][0]['message']);
        self::assertStringNotContainsString('Page', $result['body']['errors'][0]['message']);
    }

    #[Test]
    public function anonymousGetMutationIsRejectedBeforeExecution(): void
    {
        // A GET mutation from an anonymous caller is caught by the pre-existing
        // GET-mutation CSRF guard (405, "Mutations are not allowed over GET") which
        // runs BEFORE the R11 anonymous-mutation gate (401), so for GET the 405
        // message wins. The anonymous gate is what covers POST (the only method a
        // real mutation client uses); either way the mutation is rejected before
        // any resolver runs. Accept either rejection status rather than pinning the
        // ordering of the two guards.
        $anonymousAccount = $this->createStub(AccountInterface::class);
        $anonymousAccount->method('id')->willReturn(0);
        $anonymousAccount->method('isAuthenticated')->willReturn(false);
        $endpoint = $this->createEndpointWithAccount($anonymousAccount);

        $result = $endpoint->handle('GET', '', ['query' => 'mutation { deletePage(id: "1") { deleted } }']);

        self::assertContains($result['statusCode'], [401, 405], 'A GET mutation from anonymous must be rejected.');
        self::assertArrayNotHasKey('data', $result['body']);
    }

    #[Test]
    public function anonymousQueryIsNotAffectedByTheMutationGate(): void
    {
        $anonymousAccount = $this->createStub(AccountInterface::class);
        $anonymousAccount->method('id')->willReturn(0);
        $anonymousAccount->method('isAuthenticated')->willReturn(false);
        $endpoint = $this->createEndpointWithAccount($anonymousAccount);

        $result = $endpoint->handle('POST', json_encode(['query' => '{ __typename }']));

        self::assertSame(200, $result['statusCode']);
        self::assertSame('Query', $result['body']['data']['__typename']);
    }

    #[Test]
    public function authenticatedPostMutationIsNotBlockedByTheAnonymousGate(): void
    {
        // $this->endpoint is authenticated (id 1, isAuthenticated() true) by setUp().
        $result = $this->endpoint->handle(
            'POST',
            json_encode(['query' => 'mutation { deletePage(id: "1") { deleted } }']),
        );

        self::assertNotSame(401, $result['statusCode'], 'An authenticated caller must not be rejected by the anonymous-mutation gate.');
    }
}
