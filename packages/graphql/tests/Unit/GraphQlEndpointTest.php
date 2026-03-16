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
    private GraphQlEndpoint $endpoint;

    protected function setUp(): void
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $entityTypeManager->registerCoreEntityType(new EntityType(
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

        $this->endpoint = new GraphQlEndpoint(
            entityTypeManager: $entityTypeManager,
            accessHandler: new EntityAccessHandler([]),
            account: $this->createStub(AccountInterface::class),
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
}
