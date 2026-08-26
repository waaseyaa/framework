<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\List;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\AdminSurface\List\ListMetadata;
use Waaseyaa\AdminSurface\List\SurfaceQueryPolicy;
use Waaseyaa\AdminSurface\Query\SurfaceFilterOperator;
use Waaseyaa\AdminSurface\Query\SurfaceQuery;
use Waaseyaa\AdminSurface\Query\SurfaceQueryParser;

final class SurfaceQueryPolicyTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function declaredScalarOptions(): iterable
    {
        yield 'string' => ['news', 'news'];
        yield 'integer from HTTP' => [42, '42'];
        yield 'finite float from HTTP' => [1.5, '1.5'];
        yield 'true from HTTP' => [true, 'true'];
        yield 'false from HTTP' => [false, 'false'];
        yield 'null from HTTP' => [null, 'null'];
        yield 'explicit empty string' => ['', ''];
    }

    #[Test]
    public function declared_search_filter_and_sort_are_allowed(): void
    {
        $error = SurfaceQueryPolicy::validate(new SurfaceQuery(
            filters: [
                ['field' => 'title', 'operator' => SurfaceFilterOperator::STARTS_WITH, 'value' => 'Wa'],
                ['field' => 'kind', 'operator' => SurfaceFilterOperator::EQUALS, 'value' => 'news'],
            ],
            sortField: 'changed',
            sortDirection: 'DESC',
        ), $this->metadata());

        self::assertNull($error);
    }

    #[Test]
    public function undeclared_filter_sort_and_invalid_operator_are_generic_rejections(): void
    {
        $queries = [
            new SurfaceQuery(filters: [['field' => 'secret', 'operator' => SurfaceFilterOperator::EQUALS, 'value' => 'x']]),
            new SurfaceQuery(sortField: 'secret', sortDirection: 'ASC'),
            new SurfaceQuery(filters: [['field' => 'kind', 'operator' => SurfaceFilterOperator::CONTAINS, 'value' => 'news']]),
            new SurfaceQuery(sortField: 'changed', sortDirection: 'ASC'),
        ];

        foreach ($queries as $query) {
            $error = SurfaceQueryPolicy::validate($query, $this->metadata());
            self::assertNotNull($error);
            self::assertFalse($error->ok);
            self::assertSame(400, $error->error['status']);
            self::assertSame('Invalid list query', $error->error['title']);
            self::assertSame('The requested list query is not allowed.', $error->error['detail']);
            self::assertStringNotContainsString('secret', json_encode($error->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    #[DataProvider('declaredScalarOptions')]
    public function every_declared_scalar_option_accepts_its_canonical_http_query_value(mixed $option, string $queryValue): void
    {
        $metadata = $this->metadataWithOptions([
            ['value' => $option, 'label' => 'Declared'],
        ]);

        $query = SurfaceQueryParser::fromRequest(Request::create(sprintf(
            '/admin/_surface/node?filter%%5Bkind%%5D%%5Boperator%%5D=EQUALS&filter%%5Bkind%%5D%%5Bvalue%%5D=%s',
            rawurlencode($queryValue),
        )));

        $error = SurfaceQueryPolicy::validate($query, $metadata);

        self::assertNull($error);
    }

    #[Test]
    public function optioned_filter_rejects_undeclared_empty_and_compound_values_with_one_generic_envelope(): void
    {
        $metadata = $this->metadataWithOptions([
            ['value' => 'news', 'label' => 'News'],
        ]);
        $expected = [
            'ok' => false,
            'error' => [
                'status' => 400,
                'title' => 'Invalid list query',
                'detail' => 'The requested list query is not allowed.',
            ],
        ];

        foreach (['private', '', ['nested' => 'news'], ['news']] as $value) {
            $error = SurfaceQueryPolicy::validate(new SurfaceQuery(filters: [[
                'field' => 'kind',
                'operator' => SurfaceFilterOperator::EQUALS,
                'value' => $value,
            ]]), $metadata);

            self::assertNotNull($error);
            self::assertSame($expected, $error->toArray());
            self::assertStringNotContainsString('kind', json_encode($error->toArray(), JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('private', json_encode($error->toArray(), JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('news', json_encode($error->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function empty_options_refuse_every_value_but_absent_options_remain_free_form(): void
    {
        $emptyOptions = $this->metadataWithOptions([]);
        $query = new SurfaceQuery(filters: [[
            'field' => 'kind',
            'operator' => SurfaceFilterOperator::EQUALS,
            'value' => 'news',
        ]]);

        self::assertNotNull(SurfaceQueryPolicy::validate($query, $emptyOptions));

        // Mutation proof: the exact same query passes when
        // option enforcement is removed from the otherwise identical metadata.
        self::assertNull(SurfaceQueryPolicy::validate($query, $this->metadata()));

        self::assertNull(SurfaceQueryPolicy::validate(new SurfaceQuery(filters: [[
            'field' => 'kind',
            'operator' => SurfaceFilterOperator::EQUALS,
            'value' => ['free-form' => true],
        ]]), $this->metadata()));
    }

    #[Test]
    public function scalar_types_do_not_share_ambiguous_http_spellings(): void
    {
        $metadata = $this->metadataWithOptions([
            ['value' => true, 'label' => 'Enabled'],
            ['value' => null, 'label' => 'Unset'],
        ]);

        foreach (['1', '', 'TRUE', 'NULL'] as $value) {
            self::assertNotNull(SurfaceQueryPolicy::validate(new SurfaceQuery(filters: [[
                'field' => 'kind',
                'operator' => SurfaceFilterOperator::EQUALS,
                'value' => $value,
            ]]), $metadata));
        }
    }

    private function metadata(): ListMetadata
    {
        return ListMetadata::fromArray([
            'search' => ['field' => 'title', 'operator' => 'STARTS_WITH', 'label' => 'Search'],
            'filters' => [['field' => 'kind', 'operator' => 'EQUALS', 'label' => 'Kind']],
            'sorts' => [['field' => 'changed', 'direction' => 'DESC', 'label' => 'Recently changed']],
        ]) ?? throw new \LogicException('Fixture metadata must be valid.');
    }

    /** @param list<array{value: mixed, label: string}> $options */
    private function metadataWithOptions(array $options): ListMetadata
    {
        return ListMetadata::fromArray([
            'filters' => [[
                'field' => 'kind',
                'operator' => 'EQUALS',
                'label' => 'Kind',
                'options' => $options,
            ]],
        ]) ?? throw new \LogicException('Fixture metadata must be valid.');
    }
}
