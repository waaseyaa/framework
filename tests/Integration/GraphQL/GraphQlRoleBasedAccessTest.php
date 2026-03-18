<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\GraphQL\GraphQlEndpoint;
use Waaseyaa\Tests\Integration\GraphQL\Policy\RoleBasedPolicy;

final class GraphQlRoleBasedAccessTest extends GraphQlIntegrationTestBase
{
    private GraphQlEndpoint $adminEndpoint;
    private GraphQlEndpoint $anonymousEndpoint;
    private GraphQlEndpoint $memberEndpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $roleHandler = new EntityAccessHandler([new RoleBasedPolicy()]);

        $this->adminEndpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $roleHandler,
            $this->createAccount(1, ['admin', 'authenticated']),
        );

        $this->anonymousEndpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $roleHandler,
            $this->createAccount(0, ['anonymous']),
        );

        $this->memberEndpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $roleHandler,
            $this->createAccount(2, ['authenticated', 'member']),
        );
    }

    private function queryAs(GraphQlEndpoint $endpoint, string $graphql): array
    {
        $body = json_encode(['query' => $graphql], JSON_THROW_ON_ERROR);
        return $endpoint->handle('POST', $body)['body'];
    }

    public function testAdminSeesAllEntitiesAndFields(): void
    {
        $response = $this->queryAs($this->adminEndpoint, '
            { articleList { items { title } total } }
        ');

        $this->assertNoErrors($response);
        $this->assertSame(2, $response['data']['articleList']['total']);
        $this->assertCount(2, $response['data']['articleList']['items']);

        // Admin can see secret field.
        $author = $this->queryAs($this->adminEndpoint, '{ author(id: "1") { name secret } }');
        $this->assertNoErrors($author);
        $this->assertSame('classified', $author['data']['author']['secret']);
    }

    public function testAnonymousSeesNothing(): void
    {
        $response = $this->queryAs($this->anonymousEndpoint, '
            { articleList { items { title } total } }
        ');

        $this->assertNoErrors($response);

        $items = $response['data']['articleList']['items'] ?? [];
        $this->assertCount(0, $items);
    }

    public function testMemberSeesFilteredResults(): void
    {
        $response = $this->queryAs($this->memberEndpoint, '
            { articleList { items { title } total } }
        ');

        $this->assertNoErrors($response);
        $this->assertCount(2, $response['data']['articleList']['items']);

        // Member cannot see secret field.
        $author = $this->queryAs($this->memberEndpoint, '{ author(id: "1") { name secret } }');
        $this->assertNoErrors($author);
        $this->assertNull($author['data']['author']['secret']);
    }
}
