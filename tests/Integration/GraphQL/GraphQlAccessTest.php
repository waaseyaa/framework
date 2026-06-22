<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

final class GraphQlAccessTest extends GraphQlIntegrationTestBase
{
    public function testAccessDeniedEntitiesExcludedFromList(): void
    {
        $response = $this->query('{ articleList { items { id title } total } }');

        $this->assertNoErrors($response);
        $data = $response['data']['articleList'];

        // #1702 (C-7): total is access-filtered and reconciles with items — the
        // denied article (id=2) counts toward NEITHER. Previously total was the
        // unfiltered count (2), leaking the restricted collection's cardinality
        // even though the row never appeared in items.
        $this->assertSame(1, $data['total']);

        // article2 (id=2) should be absent from items (silently filtered).
        $ids = array_column($data['items'], 'id');
        $this->assertNotContains('2', $ids);
        $this->assertNotContains(2, $ids);
        $this->assertCount(1, $data['items']);
    }

    public function testFieldLevelAccessFiltersRestrictedFields(): void
    {
        $response = $this->query('{ author(id: "1") { name secret } }');

        $this->assertNoErrors($response);
        $author = $response['data']['author'];

        // name should be present.
        $this->assertSame('Alice', $author['name']);

        // secret should be null (filtered by RestrictFieldPolicy).
        $this->assertNull($author['secret']);
    }
}
