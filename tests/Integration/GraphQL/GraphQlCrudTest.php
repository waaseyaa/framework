<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

final class GraphQlCrudTest extends GraphQlIntegrationTestBase
{
    public function testListReturnsPersistentEntities(): void
    {
        $response = $this->query('{ articleList { items { title } total } }');

        $this->assertNoErrors($response);
        $data = $response['data']['articleList'];

        // #1702 (C-7): total is access-filtered — article2 (denied by
        // DenyByIdPolicy) counts toward neither items nor total. Previously total
        // was the unfiltered count (2).
        $this->assertSame(1, $data['total']);

        // items contains only article1 (article2 denied by DenyByIdPolicy).
        $titles = array_column($data['items'], 'title');
        $this->assertContains('Hello', $titles);
        $this->assertNotContains('World', $titles);
    }

    public function testCreateMutationPersistsAndReturns(): void
    {
        $response = $this->query('
            mutation {
                createArticle(input: { title: "New Article" }) {
                    id
                    title
                }
            }
        ');

        $this->assertNoErrors($response);
        $created = $response['data']['createArticle'];
        $this->assertSame('New Article', $created['title']);
        $this->assertNotEmpty($created['id']);

        // Verify it persists: query it back.
        $id = $created['id'];
        $verify = $this->query("{ article(id: \"{$id}\") { title } }");
        $this->assertNoErrors($verify);
        $this->assertSame('New Article', $verify['data']['article']['title']);
    }

    public function testUpdateMutationModifiesEntity(): void
    {
        $token = $this->mutationToken('article', 1);
        $response = $this->query(<<<GRAPHQL
                mutation {
                    updateArticle(id: "1", input: { title: "Updated", mutationToken: "{$token}" }) {
                        title
                    }
                }
            GRAPHQL);

        $this->assertNoErrors($response);
        $this->assertSame('Updated', $response['data']['updateArticle']['title']);

        // Verify persistence.
        $verify = $this->query('{ article(id: "1") { title } }');
        $this->assertNoErrors($verify);
        $this->assertSame('Updated', $verify['data']['article']['title']);
    }

    public function testDeleteMutationRemovesEntity(): void
    {
        // Create a temporary entity to delete (don't delete seeded data).
        $create = $this->query('
            mutation {
                createArticle(input: { title: "Temp" }) { id mutationToken }
            }
        ');
        $id = $create['data']['createArticle']['id'];
        $token = $create['data']['createArticle']['mutationToken'];

        $response = $this->query("
            mutation {
                deleteArticle(id: \"{$id}\", mutationToken: \"{$token}\") {
                    deleted
                }
            }
        ");

        $this->assertNoErrors($response);
        $this->assertTrue($response['data']['deleteArticle']['deleted']);

        // Verify it's gone.
        $verify = $this->query("{ article(id: \"{$id}\") { title } }");
        $this->assertNoErrors($verify);
        $this->assertNull($verify['data']['article']);
    }
}
