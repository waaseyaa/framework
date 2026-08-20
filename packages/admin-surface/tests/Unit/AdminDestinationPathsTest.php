<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\AdminDestinationPaths;

#[CoversClass(AdminDestinationPaths::class)]
final class AdminDestinationPathsTest extends TestCase
{
    #[Test]
    public function listDestinationAddressesTheEntityType(): void
    {
        self::assertSame('/admin/node', AdminDestinationPaths::list('node'));
    }

    #[Test]
    public function createDestinationAddressesTheEntityType(): void
    {
        self::assertSame('/admin/node/create', AdminDestinationPaths::create('node'));
    }

    #[Test]
    public function editDestinationAddressesOneRecord(): void
    {
        self::assertSame('/admin/node/42', AdminDestinationPaths::edit('node', '42'));
    }

    #[Test]
    public function historyDestinationAddressesOneRecord(): void
    {
        self::assertSame('/admin/node/42/history', AdminDestinationPaths::history('node', '42'));
    }

    /**
     * History answers for a record; the pipeline answers for the type. Linking
     * one at the other is precisely the mislabelling #2419 refused to ship.
     */
    #[Test]
    public function historyIsNotThePipelineAndNotTheEditor(): void
    {
        $history = AdminDestinationPaths::history('node', '42');

        self::assertNotSame(AdminDestinationPaths::pipeline('node'), $history);
        self::assertNotSame(AdminDestinationPaths::edit('node', '42'), $history);
    }

    #[Test]
    public function historyRefusesAnEmptyRecordId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AdminDestinationPaths::history('node', '');
    }

    #[Test]
    public function historyEncodesItsPathSegments(): void
    {
        self::assertSame('/admin/node/a%20b%2Fc/history', AdminDestinationPaths::history('node', 'a b/c'));
    }

    #[Test]
    public function pipelineDestinationAddressesTheEntityType(): void
    {
        self::assertSame('/admin/node/pipeline', AdminDestinationPaths::pipeline('node'));
    }

    #[Test]
    public function listAcceptsABundleScope(): void
    {
        self::assertSame('/admin/node?bundle=post', AdminDestinationPaths::list('node', 'post'));
    }

    #[Test]
    public function filteredListOwnsTheSpaDeclaredFilterQueryShape(): void
    {
        self::assertSame(
            '/admin/node?filter%5Bworkflow_state%5D%5Boperator%5D=EQUALS&filter%5Bworkflow_state%5D%5Bvalue%5D=draft',
            AdminDestinationPaths::filteredList('node', [
                'workflow_state' => ['operator' => 'EQUALS', 'value' => 'draft'],
            ]),
        );
    }

    #[Test]
    public function filteredListIsStableAndRfc3986Encoded(): void
    {
        $expected = '/admin/odd%20type'
            . '?filter%5Ba_field.sub%5D%5Boperator%5D=CONTAINS'
            . '&filter%5Ba_field.sub%5D%5Bvalue%5D=a%20b%26c'
            . '&filter%5Bz%5D%5Boperator%5D=EQUALS'
            . '&filter%5Bz%5D%5Bvalue%5D=0';

        self::assertSame($expected, AdminDestinationPaths::filteredList('odd type', [
            'z' => ['operator' => 'EQUALS', 'value' => '0'],
            'a_field.sub' => ['operator' => 'CONTAINS', 'value' => 'a b&c'],
        ]));
    }

    #[Test]
    #[DataProvider('canonicalFilterFields')]
    public function filteredListPreservesCanonicalFieldNames(string $field): void
    {
        self::assertSame(
            sprintf(
                '/admin/node?filter%%5B%s%%5D%%5Boperator%%5D=EQUALS&filter%%5B%s%%5D%%5Bvalue%%5D=draft',
                $field,
                $field,
            ),
            AdminDestinationPaths::filteredList('node', [
                $field => ['operator' => 'EQUALS', 'value' => 'draft'],
            ]),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function canonicalFilterFields(): iterable
    {
        yield 'plain' => ['state'];
        yield 'underscored' => ['workflow_state'];
        yield 'leading underscore' => ['_internal'];
        yield 'dotted' => ['owner.name'];
        yield 'digits after first character' => ['field2'];
    }

    /**
     * The generator may not emit a field name the list metadata would refuse:
     * a forged bracket would fabricate a differently shaped key, and a space or
     * control character would address a field no declaration can name.
     */
    #[Test]
    #[DataProvider('noncanonicalFilterFields')]
    public function filteredListRefusesANoncanonicalFieldName(string $field): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AdminDestinationPaths::filteredList('node', [
            $field => ['operator' => 'EQUALS', 'value' => 'draft'],
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function noncanonicalFilterFields(): iterable
    {
        yield 'bracket injection' => ['a][x'];
        yield 'opening bracket' => ['a[x'];
        yield 'closing bracket' => ['a]'];
        yield 'inner space' => ['a field'];
        yield 'leading space' => [' state'];
        yield 'trailing space' => ['state '];
        yield 'tab' => ["state\tx"];
        yield 'newline' => ["state\n"];
        yield 'carriage return' => ["state\rx"];
        yield 'null byte' => ["state\0"];
        yield 'leading digit' => ['1state'];
        yield 'leading dot' => ['.state'];
        yield 'forward slash' => ['a/b'];
        yield 'backslash' => ['a\\b'];
        yield 'hyphen' => ['a-b'];
        yield 'ampersand' => ['a&b'];
        yield 'equals' => ['a=b'];
        yield 'percent' => ['a%b'];
        yield 'non-ascii' => ['état'];
    }

    /**
     * An operator is serialized so the list can compare it with the one its
     * metadata declares. A name that is no canonical operator could never match
     * that declaration, so it is refused rather than emitted.
     */
    #[Test]
    public function filteredListRefusesANoncanonicalOperator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AdminDestinationPaths::filteredList('node', [
            'state' => ['operator' => 'LIKE', 'value' => 'draft'],
        ]);
    }

    #[Test]
    public function filteredListEmitsOperatorsInCanonicalForm(): void
    {
        self::assertSame(
            '/admin/node?filter%5Bstate%5D%5Boperator%5D=STARTS_WITH&filter%5Bstate%5D%5Bvalue%5D=dr',
            AdminDestinationPaths::filteredList('node', [
                'state' => ['operator' => 'starts_with', 'value' => 'dr'],
            ]),
        );
    }

    /** @param array<mixed> $filters */
    #[Test]
    #[DataProvider('invalidListFilters')]
    public function filteredListRefusesAnEmptyOrMalformedTuple(array $filters): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AdminDestinationPaths::filteredList('node', $filters);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidListFilters(): iterable
    {
        yield 'no filters' => [[]];
        yield 'empty field' => [['' => ['operator' => 'EQUALS', 'value' => 'draft']]];
        yield 'numeric field' => [[0 => ['operator' => 'EQUALS', 'value' => 'draft']]];
        yield 'not a tuple' => [['state' => 'draft']];
        yield 'missing operator' => [['state' => ['value' => 'draft']]];
        yield 'empty operator' => [['state' => ['operator' => '', 'value' => 'draft']]];
        yield 'missing value' => [['state' => ['operator' => 'EQUALS']]];
        yield 'empty value' => [['state' => ['operator' => 'EQUALS', 'value' => '']]];
        yield 'non-string value' => [['state' => ['operator' => 'EQUALS', 'value' => false]]];
        yield 'extra member' => [['state' => ['operator' => 'EQUALS', 'value' => 'draft', 'label' => 'Draft']]];
        yield 'unknown operator' => [['state' => ['operator' => 'NOT_AN_OPERATOR', 'value' => 'draft']]];
    }

    #[Test]
    public function createAcceptsABundleScope(): void
    {
        self::assertSame('/admin/node/create?bundle=job_posting', AdminDestinationPaths::create('node', 'job_posting'));
    }

    /**
     * An empty bundle is the same statement as "no bundle". Emitting `?bundle=`
     * would hand the SPA a value it must then treat as absent.
     */
    #[Test]
    public function anEmptyBundleIsOmittedRatherThanEmitted(): void
    {
        self::assertSame('/admin/node', AdminDestinationPaths::list('node', ''));
        self::assertSame('/admin/node/create', AdminDestinationPaths::create('node', ''));
    }

    /**
     * Encoding is the generator's job. A caller that has to remember
     * rawurlencode() is the hand-built string this class exists to remove.
     */
    #[Test]
    public function pathSegmentsAreEncoded(): void
    {
        self::assertSame('/admin/node/a%20b%2Fc', AdminDestinationPaths::edit('node', 'a b/c'));
        self::assertSame('/admin/odd%20type', AdminDestinationPaths::list('odd type'));
    }

    #[Test]
    public function bundleScopeIsQueryEncoded(): void
    {
        self::assertSame('/admin/node?bundle=a%20b%26c', AdminDestinationPaths::list('node', 'a b&c'));
    }

    #[Test]
    public function anEmptyEntityTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AdminDestinationPaths::list('');
    }

    #[Test]
    public function anEmptyRecordIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AdminDestinationPaths::edit('node', '');
    }

    /**
     * The bundle query parameter is one contract shared with the SPA (#2418).
     * If this name changes, both sides change together.
     */
    #[Test]
    public function theBundleQueryParameterIsNamedBundle(): void
    {
        self::assertSame('bundle', AdminDestinationPaths::QUERY_BUNDLE);
    }

    /**
     * Every generated destination must correspond to a real page in the admin
     * SPA's file-based router. Moving or renaming one of those page files
     * breaks this test rather than silently breaking every consumer link.
     *
     * @param non-empty-string $destination
     * @param non-empty-string $pageFile
     */
    #[Test]
    #[DataProvider('destinationPageFiles')]
    public function everyDestinationIsBackedByAnAdminSpaPage(string $destination, string $pageFile): void
    {
        $path = \dirname(__DIR__, 3) . '/admin/app/pages/' . $pageFile;

        self::assertFileExists(
            $path,
            sprintf('%s generates %s, which requires the SPA page %s', AdminDestinationPaths::class, $destination, $pageFile),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function destinationPageFiles(): iterable
    {
        yield 'list' => [AdminDestinationPaths::list('node'), '[entityType]/index.vue'];
        yield 'create' => [AdminDestinationPaths::create('node'), '[entityType]/create.vue'];
        yield 'edit' => [AdminDestinationPaths::edit('node', '1'), '[entityType]/[id]/index.vue'];
        yield 'history' => [AdminDestinationPaths::history('node', '1'), '[entityType]/[id]/history.vue'];
        yield 'pipeline' => [AdminDestinationPaths::pipeline('node'), '[entityType]/pipeline.vue'];
    }

    /**
     * The SPA must actually consume the parameter this generator emits. A
     * generator that emits `?bundle=` against pages that ignore it promises
     * precision the destination does not deliver — the exact failure #2418 was
     * filed for.
     *
     * The TypeScript constant is the SPA's half of the same contract, so it is
     * pinned to the PHP one by value rather than by convention.
     */
    #[Test]
    public function theAdminSpaDeclaresTheSameBundleQueryParameter(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 3) . '/admin/app/runtime/bundleScope.ts');
        self::assertIsString($source);

        self::assertStringContainsString(
            sprintf("export const BUNDLE_QUERY_PARAM = '%s'", AdminDestinationPaths::QUERY_BUNDLE),
            $source,
            'The admin SPA must name the bundle query parameter exactly as this generator emits it.',
        );
    }

    /**
     * The SPA half of the restoration contract. The behavioural proof lives in
     * the SchemaList Vitest suite; this pins only that the page still reads
     * both members of the pair and gates restoration on the declared operator,
     * so the query shape this generator emits cannot drift away from the one
     * the list consumes.
     */
    #[Test]
    public function theAdminSpaRestoresTheSameFilteredListQueryShape(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 3) . '/admin/app/components/schema/SchemaList.vue');
        self::assertIsString($source);

        self::assertStringContainsString('params.get(`filter[${field}][operator]`)', $source);
        self::assertStringContainsString('params.get(`filter[${field}][value]`)', $source);
        self::assertStringContainsString('urlOperator === declaredOperator', $source);
        self::assertStringContainsString('url.searchParams.set(`filter[${filter.field}][operator]`, filter.operator)', $source);
        self::assertStringContainsString('url.searchParams.set(`filter[${filter.field}][value]`, String(value))', $source);
    }

    /**
     * Both bundle-scoped destinations must route their query read through that
     * shared helper, so neither page can drift into reading a different
     * parameter or skipping the degradation rules.
     *
     * @param non-empty-string $page
     */
    #[Test]
    #[DataProvider('bundleScopedPages')]
    public function eachBundleScopedPageReadsTheScopeThroughTheSharedHelper(string $page): void
    {
        $source = file_get_contents(\dirname(__DIR__, 3) . '/admin/app/pages/[entityType]/' . $page);
        self::assertIsString($source);

        self::assertStringContainsString("from '~/runtime/bundleScope'", $source, $page . ' must import the shared helper.');
        self::assertStringContainsString('readBundleScope(route.query)', $source, $page . ' must read the bundle scope from the query.');
    }

    /** @return iterable<string, array{string}> */
    public static function bundleScopedPages(): iterable
    {
        yield 'create' => ['create.vue'];
        yield 'list' => ['index.vue'];
    }
}
