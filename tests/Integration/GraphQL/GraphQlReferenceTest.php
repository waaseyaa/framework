<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

final class GraphQlReferenceTest extends GraphQlIntegrationTestBase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Articles 3 and 4 reference each other for circular reference testing.
        // IDs are deterministic: fresh in-memory SQLite, parent seeds 2 articles.
        // Article 3 references not-yet-created article 4 (SQLite FK enforcement is off).
        $article3 = $this->storages['article']->create([
            'title' => 'Ping', 'body' => 'Content 3', 'author_id' => 1, 'related_article_id' => 4,
        ]);
        $this->storages['article']->save($article3);

        $article4 = $this->storages['article']->create([
            'title' => 'Pong', 'body' => 'Content 4', 'author_id' => 1, 'related_article_id' => 3,
        ]);
        $this->storages['article']->save($article4);
    }

    public function testReferenceResolvesNestedEntity(): void
    {
        $response = $this->query('
            { article(id: "1") { title author_id { name } } }
        ');

        $this->assertNoErrors($response);
        $article = $response['data']['article'];
        $this->assertSame('Hello', $article['title']);
        $this->assertSame('Alice', $article['author_id']['name']);
    }

    public function testMultiHopReferenceResolves(): void
    {
        $response = $this->query('
            { article(id: "1") { author_id { organization_id { name } } } }
        ');

        $this->assertNoErrors($response);
        $org = $response['data']['article']['author_id']['organization_id'];
        $this->assertSame('Acme', $org['name']);
    }

    public function testCircularReferenceRespectsDepthLimit(): void
    {
        // Articles 3 and 4 reference each other (3->4->3->4...).
        // maxDepth=3 is the default in GraphQlEndpoint. Three hops should hit the limit.
        $response = $this->query('
            {
                article(id: "3") {
                    related_article_id {
                        related_article_id {
                            related_article_id {
                                title
                            }
                        }
                    }
                }
            }
        ');

        $this->assertNoErrors($response);
        $article = $response['data']['article'];
        $this->assertNotNull($article['related_article_id'], 'Depth 1 should resolve');

        $depth2 = $article['related_article_id']['related_article_id'] ?? null;
        $this->assertNotNull($depth2, 'Depth 2 should resolve');

        $depth3 = $depth2['related_article_id'] ?? null;
        $this->assertNull($depth3, 'Depth 3 should return null (maxDepth exceeded)');
    }
}
