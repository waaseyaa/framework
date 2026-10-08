<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\Operator;
use Waaseyaa\Listing\Sort;
use Waaseyaa\Listing\SortDirection;

#[CoversClass(ListingDefinition::class)]
final class ListingDefinitionTest extends TestCase
{
    #[Test]
    public function defaultsAreSpecCompliant(): void
    {
        $def = new ListingDefinition(id: 'recent_nodes', entityType: 'node');

        self::assertSame('recent_nodes', $def->id);
        self::assertSame('node', $def->entityType);
        self::assertNull($def->bundle);
        self::assertSame([], $def->filters);
        self::assertSame([], $def->sorts);
        self::assertSame(20, $def->pageSize);
        self::assertSame(['view'], $def->accessOps);
        self::assertFalse($def->approximateTotal);
        self::assertNull($def->cacheTtl);
        self::assertFalse($def->isUnbounded());
    }

    // ----- id invariant -----

    #[Test]
    public function idRejectsCapitalLetters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ListingDefinition(id: 'Recent', entityType: 'node');
    }

    #[Test]
    public function idRejectsLeadingDigit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ListingDefinition(id: '1bad', entityType: 'node');
    }

    #[Test]
    public function idRejectsDashes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ListingDefinition(id: 'bad-id', entityType: 'node');
    }

    #[Test]
    public function idAcceptsLowerSnake(): void
    {
        $def = new ListingDefinition(id: 'a1_b2_c3', entityType: 'node');
        self::assertSame('a1_b2_c3', $def->id);
    }

    // ----- entityType invariant -----

    #[Test]
    public function entityTypeMustBeNonEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ListingDefinition(id: 'ok', entityType: '');
    }

    // ----- bundle invariant -----

    #[Test]
    public function bundleMayBeNullOrNonEmpty(): void
    {
        $a = new ListingDefinition(id: 'ok', entityType: 'node');
        self::assertNull($a->bundle);

        $b = new ListingDefinition(id: 'ok', entityType: 'node', bundle: 'article');
        self::assertSame('article', $b->bundle);
    }

    #[Test]
    public function bundleEmptyStringRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ListingDefinition(id: 'ok', entityType: 'node', bundle: '');
    }

    // ----- accessOps invariant -----

    #[Test]
    public function accessOpsCannotBeEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ListingDefinition(id: 'ok', entityType: 'node', accessOps: []);
    }

    #[Test]
    public function accessOpsRejectsEmptyStringEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ListingDefinition(id: 'ok', entityType: 'node', accessOps: ['']);
    }

    // ----- filters / sorts type invariants -----

    #[Test]
    public function filtersMustAllBeFilterDefinition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore-next-line — intentional type violation */
        new ListingDefinition(id: 'ok', entityType: 'node', filters: ['not a filter']);
    }

    #[Test]
    public function sortsMustAllBeSortDefinition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore-next-line — intentional type violation */
        new ListingDefinition(id: 'ok', entityType: 'node', sorts: ['not a sort']);
    }

    #[Test]
    public function acceptsValidFilterAndSortLists(): void
    {
        $def = new ListingDefinition(
            id: 'ok',
            entityType: 'node',
            filters: [Filter::eq('status', 1), Filter::gte('weight', 0)],
            sorts: [Sort::desc('created'), Sort::asc('weight')],
        );

        self::assertCount(2, $def->filters);
        self::assertCount(2, $def->sorts);
        self::assertSame(Operator::EQ, $def->filters[0]->op);
        self::assertSame(SortDirection::DESC, $def->sorts[0]->direction);
    }

    // ----- pageSize / cacheTtl invariants -----

    #[Test]
    public function pageSizeMustBeNullOrPositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ListingDefinition(id: 'ok', entityType: 'node', pageSize: 0);
    }

    #[Test]
    public function pageSizeRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ListingDefinition(id: 'ok', entityType: 'node', pageSize: -5);
    }

    #[Test]
    public function pageSizeAcceptsNull(): void
    {
        $def = new ListingDefinition(id: 'ok', entityType: 'node', pageSize: null);
        self::assertNull($def->pageSize);
    }

    #[Test]
    public function cacheTtlMustBeNullOrPositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ListingDefinition(id: 'ok', entityType: 'node', cacheTtl: 0);
    }

    // ----- allowUnbounded / isUnbounded -----

    #[Test]
    public function allowUnboundedReturnsCloneWithFlagSet(): void
    {
        $base = new ListingDefinition(id: 'ok', entityType: 'node');
        $unbounded = $base->allowUnbounded();

        self::assertFalse($base->isUnbounded());
        self::assertTrue($unbounded->isUnbounded());
        self::assertNotSame($base, $unbounded);
        // All other params preserved
        self::assertSame($base->id, $unbounded->id);
        self::assertSame($base->pageSize, $unbounded->pageSize);
        self::assertSame($base->accessOps, $unbounded->accessOps);
    }

    // ----- effectiveContexts -----

    #[Test]
    public function effectiveContextsIncludesUrlQueryPageWhenPaged(): void
    {
        $def = new ListingDefinition(id: 'ok', entityType: 'node', pageSize: 20);
        $contexts = $def->effectiveContexts($this->stubEntityType(translatable: false));
        self::assertContains('url.query.page', $contexts);
    }

    #[Test]
    public function effectiveContextsOmitsUrlQueryPageWhenPageSizeNull(): void
    {
        $def = new ListingDefinition(id: 'ok', entityType: 'node', pageSize: null);
        $contexts = $def->effectiveContexts($this->stubEntityType(translatable: false));
        self::assertNotContains('url.query.page', $contexts);
    }

    #[Test]
    public function effectiveContextsAddsLanguageContextForTranslatableEntity(): void
    {
        $def = new ListingDefinition(id: 'ok', entityType: 'node');
        $contexts = $def->effectiveContexts($this->stubEntityType(translatable: true));
        self::assertContains('language.content', $contexts);
    }

    #[Test]
    public function effectiveContextsOmitsLanguageContextForNonTranslatable(): void
    {
        $def = new ListingDefinition(id: 'ok', entityType: 'node');
        $contexts = $def->effectiveContexts($this->stubEntityType(translatable: false));
        self::assertNotContains('language.content', $contexts);
    }

    #[Test]
    public function effectiveContextsAddsUserRolesWhenAccessOpsNonDefault(): void
    {
        $def = new ListingDefinition(id: 'ok', entityType: 'node', accessOps: ['update']);
        $contexts = $def->effectiveContexts($this->stubEntityType(translatable: false));
        self::assertContains('user.roles', $contexts);
    }

    #[Test]
    public function effectiveContextsOmitsUserRolesWhenAccessOpsDefault(): void
    {
        $def = new ListingDefinition(id: 'ok', entityType: 'node');
        $contexts = $def->effectiveContexts($this->stubEntityType(translatable: false));
        self::assertNotContains('user.roles', $contexts);
    }

    #[Test]
    public function effectiveContextsIncludesPerExposedParam(): void
    {
        $def = new ListingDefinition(
            id: 'ok',
            entityType: 'node',
            filters: [
                Filter::exposed(Filter::eq('title', ''), 'q'),
                Filter::exposed(Filter::gte('created', 0), 'after'),
            ],
        );
        $contexts = $def->effectiveContexts($this->stubEntityType(translatable: false));
        self::assertContains('url.query.q', $contexts);
        self::assertContains('url.query.after', $contexts);
    }

    #[Test]
    public function effectiveContextsIsDeterministicAndUnique(): void
    {
        $def = new ListingDefinition(
            id: 'ok',
            entityType: 'node',
            filters: [
                Filter::exposed(Filter::eq('title', ''), 'zeta'),
                Filter::exposed(Filter::eq('status', 1), 'alpha'),
            ],
        );
        $first = $def->effectiveContexts($this->stubEntityType(translatable: true));
        $second = $def->effectiveContexts($this->stubEntityType(translatable: true));

        self::assertSame($first, $second, 'effectiveContexts must be deterministic.');
        self::assertSame(array_values(array_unique($first)), $first, 'no duplicates expected.');
    }

    // ----- cacheKeyHash -----

    #[Test]
    public function cacheKeyHashIsSixteenHexChars(): void
    {
        $def = new ListingDefinition(id: 'ok', entityType: 'node');
        $hash = $def->cacheKeyHash();
        self::assertSame(16, strlen($hash));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $hash);
        self::assertNotSame(
            new ListingDefinition(id: 'ok', entityType: 'node', filters: [Filter::eq('weight', 1)])->cacheKeyHash(),
            new ListingDefinition(id: 'ok', entityType: 'node', filters: [Filter::eq('weight', 1.0)])->cacheKeyHash(),
            'Definition hashes preserve integer versus float identity.',
        );
    }

    #[Test]
    public function cacheKeyHashIsDeterministicAcrossEquivalentConstructions(): void
    {
        $a = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [Filter::eq('status', 1), Filter::gte('weight', 0)],
            sorts: [Sort::desc('created')],
        );
        $b = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [Filter::eq('status', 1), Filter::gte('weight', 0)],
            sorts: [Sort::desc('created')],
        );
        self::assertSame($a->cacheKeyHash(), $b->cacheKeyHash());
    }

    #[Test]
    public function cacheKeyHashChangesWhenAnyFieldChanges(): void
    {
        $base = new ListingDefinition(id: 'ok', entityType: 'node');

        self::assertNotSame(
            $base->cacheKeyHash(),
            new ListingDefinition(id: 'ok2', entityType: 'node')->cacheKeyHash(),
        );
        self::assertNotSame(
            $base->cacheKeyHash(),
            new ListingDefinition(id: 'ok', entityType: 'taxonomy_term')->cacheKeyHash(),
        );
        self::assertNotSame(
            $base->cacheKeyHash(),
            new ListingDefinition(id: 'ok', entityType: 'node', pageSize: 50)->cacheKeyHash(),
        );
        self::assertNotSame(
            $base->cacheKeyHash(),
            $base->allowUnbounded()->cacheKeyHash(),
        );
    }

    #[Test]
    public function cacheKeyHashStableUnderEquivalentFilterOrdering(): void
    {
        // Filter order is part of the listing semantics (affects SQL emit
        // ordering), so changing filter order MUST produce a different
        // cache key. This guards against accidentally sorting filters.
        $a = new ListingDefinition(
            id: 'ok',
            entityType: 'node',
            filters: [Filter::eq('a', 1), Filter::eq('b', 2)],
        );
        $b = new ListingDefinition(
            id: 'ok',
            entityType: 'node',
            filters: [Filter::eq('b', 2), Filter::eq('a', 1)],
        );

        self::assertNotSame($a->cacheKeyHash(), $b->cacheKeyHash());
    }

    /**
     * Anonymous-class stub for {@see EntityTypeInterface} — only
     * `isTranslatable()` is read by {@see ListingDefinition::effectiveContexts()}.
     */
    private function stubEntityType(bool $translatable): EntityTypeInterface
    {
        return new class ($translatable) implements EntityTypeInterface {
            public function __construct(private readonly bool $translatable) {}

            public function id(): string
            {
                return 'stub';
            }

            public function getLabel(): string
            {
                return 'Stub';
            }

            public function getClass(): string
            {
                return self::class;
            }

            public function getStorageClass(): string
            {
                /** @var class-string<EntityStorageInterface> */
                return EntityStorageInterface::class;
            }

            public function getKeys(): array
            {
                return ['id' => 'id'];
            }

            public function isRevisionable(): bool
            {
                return false;
            }

            public function getRevisionDefault(): bool
            {
                return false;
            }

            public function isTranslatable(): bool
            {
                return $this->translatable;
            }

            public function getBundleEntityType(): ?string
            {
                return null;
            }

            public function getConstraints(): array
            {
                return [];
            }

            /** @return array<string, FieldDefinitionInterface> */
            public function getFieldDefinitions(): array
            {
                return [];
            }

            public function getPrimaryStorageBackend(): ?string
            {
                return null;
            }

            public function getGroup(): ?string
            {
                return null;
            }

            public function getDescription(): ?string
            {
                return null;
            }

            public function getTenancy(): ?array
            {
                return null;
            }
        };
    }
}
