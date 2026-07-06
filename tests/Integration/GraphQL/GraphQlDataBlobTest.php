<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

/**
 * Tests filter and sort behavior on fields stored in the _data JSON blob (#438).
 *
 * SqlSchemaHandler creates dedicated columns only for entity keys (id, uuid,
 * label, bundle, langcode). All other fields are stored in a _data TEXT column
 * as JSON. SqlEntityQuery::resolveField() detects this at runtime via
 * fieldExists() and wraps them in json_extract(_data, '$.fieldname').
 *
 * These queries work but cannot use column indexes. For high-traffic query
 * fields, promote them to dedicated schema columns in SqlSchemaHandler.
 */
final class GraphQlDataBlobTest extends GraphQlIntegrationTestBase
{
    public function testFilterOnSchemaColumnWorks(): void
    {
        // 'title' is the label key → dedicated column.
        $response = $this->query('
            {
                articleList(filter: [{ field: "title", value: "Hello" }]) {
                    items { title }
                    total
                }
            }
        ');

        $this->assertNoErrors($response);
        $items = $response['data']['articleList']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('Hello', $items[0]['title']);
    }

    public function testFilterOnDataBlobFieldWorks(): void
    {
        // 'body' is not an entity key → stored in _data blob.
        // SqlEntityQuery uses json_extract(_data, '$.body') transparently.
        $response = $this->query('
            {
                articleList(filter: [{ field: "body", value: "Content 1" }]) {
                    items { title }
                    total
                }
            }
        ');

        $this->assertNoErrors($response);
        $items = $response['data']['articleList']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('Hello', $items[0]['title']);
    }

    public function testSortOnSchemaColumnWorks(): void
    {
        // Sort by title (schema column) descending.
        $response = $this->query('
            {
                articleList(sort: "-title") {
                    items { title }
                }
            }
        ');

        $this->assertNoErrors($response);
        $items = $response['data']['articleList']['items'];
        // Only article 1 visible (article 2 denied). With one item, just verify it works.
        $this->assertNotEmpty($items);
    }

    public function testSortOnDataBlobFieldWorks(): void
    {
        // Sort by body (_data field) ascending.
        $response = $this->query('
            {
                articleList(sort: "body") {
                    items { title }
                }
            }
        ');

        $this->assertNoErrors($response);
        $items = $response['data']['articleList']['items'];
        $this->assertNotEmpty($items);
    }

    public function testFilterOnDataBlobFieldWithContainsOperator(): void
    {
        // CONTAINS operator on a _data string field ('location' on organization).
        // CONTAINS wraps value in %...% and uses LIKE internally via SqlEntityQuery.
        $response = $this->query('
            {
                organizationList(filter: [{ field: "location", value: "YC", operator: "CONTAINS" }]) {
                    items { name }
                    total
                }
            }
        ');

        $this->assertNoErrors($response);
        $items = $response['data']['organizationList']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('Acme', $items[0]['name']);
    }

    public function testFilterOnNonExistentFieldReturnsEmpty(): void
    {
        // Filtering on a field that doesn't exist in the entity at all.
        // json_extract(_data, '$.nonexistent') returns NULL → no match.
        $response = $this->query('
            {
                articleList(filter: [{ field: "nonexistent", value: "anything" }]) {
                    items { title }
                    total
                }
            }
        ');

        $this->assertNoErrors($response);
        $this->assertCount(0, $response['data']['articleList']['items']);
    }

    public function testSortOnDataBlobFieldWithMultipleEntities(): void
    {
        // Sort mechanics on a _data blob field, exercised on a field the account
        // MAY read. `bio` is a plain _data blob field (not an entity key, not
        // field-restricted): Alice bio='Writer', Bob bio='Editor'.
        // Sort by bio ascending → 'Editor' before 'Writer' → Bob before Alice.
        //
        // NOTE: this test previously sorted on `secret`, which the harness's
        // RestrictFieldPolicy('author', 'secret') field-forbids for this account.
        // That relied on the R14 ordering oracle (ordering the list by a value
        // the caller cannot read); the fix now excludes such rows
        // value-independently (see testSortOnFieldForbiddenBlobFieldYieldsNothing
        // and packages/graphql .../EntityResolverFieldFilterOracleTest).
        $response = $this->query('
            {
                authorList(sort: "bio") {
                    items { name }
                }
            }
        ');

        $this->assertNoErrors($response);
        $items = $response['data']['authorList']['items'];
        $this->assertCount(2, $items);
        // 'Editor' < 'Writer' alphabetically.
        $this->assertSame('Bob', $items[0]['name']);
        $this->assertSame('Alice', $items[1]['name']);
    }

    public function testSortOnFieldForbiddenBlobFieldIsRejected(): void
    {
        // R14 (audit A11): `secret` is a plain _data blob field field-forbidden
        // for this account (RestrictFieldPolicy('author', 'secret')). Sorting on
        // it must be rejected outright: storage sort/pagination run before the
        // value-independent drop, so a forbidden row would otherwise occupy an
        // observable pagination rank (empty-vs-populated page = ordering oracle).
        // Full-stack companion to EntityResolverFieldFilterOracleTest.
        $response = $this->query('
            {
                authorList(sort: "secret") {
                    items { name }
                    total
                }
            }
        ');

        $this->assertHasError($response, "Cannot sort by field 'secret'");
    }
}
