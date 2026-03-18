<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GraphQL\GraphQlEndpoint;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(GraphQlEndpoint::class)]
final class GraphQlEndpointTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private GraphQlEndpoint $endpoint;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->entityTypeManager->registerCoreEntityType(new EntityType(
            id: 'page',
            label: 'Page',
            class: EntityBase::class,
            keys: ['id' => 'id'],
            fieldDefinitions: [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'string', 'required' => true],
                'body' => ['type' => 'text'],
            ],
        ));

        // Authenticated user (id !== 0) by default.
        $authenticatedAccount = $this->createStub(AccountInterface::class);
        $authenticatedAccount->method('id')->willReturn(1);

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
}
