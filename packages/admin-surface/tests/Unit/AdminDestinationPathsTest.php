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
