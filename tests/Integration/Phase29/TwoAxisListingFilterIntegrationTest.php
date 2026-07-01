<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase29;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Cache\ContextNames;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Listing\TwoAxisFilterResolver;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\FilterDefinition;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\Operator;

/**
 * Phase 29 integration: M-004 / WP07 — `Filter::langcode()` end-to-end on a
 * two-axis (revisionable × translatable) entity type.
 *
 * **Substrate audit verdict:** M-007's listing pipeline already ships the
 * canonical `Filter::langcode($code)` factory, the implicit `language.content`
 * cache context for translatable types, and the per-row `entity:<type>:<id>:<lc>`
 * cache-tag emission. This test confirms those substrates compose correctly
 * with the WP04 per-`(entity, langcode)` current-revision pointer via the
 * new {@see TwoAxisFilterResolver} hook:
 *
 *  - **FR-030** — `Filter::langcode('oj')` produces a canonical
 *    {@see FilterDefinition} (no new `ListingDefinition::langcode` field).
 *  - **FR-031** — entities without a `(entity_id, 'oj')` translation row are
 *    excluded by the resolver re-reading at the langcode and dropping nulls.
 *  - **FR-033** — `ListingDefinition::effectiveContexts()` auto-injects
 *    `language.content` (M-007 canonical token) for two-axis entity types;
 *    no parallel `language.requested` context is introduced.
 *  - **FR-033a** — each surviving row is read at the langcode's current
 *    revision via {@see EntityRepositoryInterface::find($id, $langcode)}.
 *
 * The test stubs the repository read path so it can simulate the per-langcode
 * pointer behaviour without booting the full coordinator/driver wiring (that
 * end-to-end path is covered by `TwoAxisSaveLoadIntegrationTest`).
 */
#[CoversNothing]
final class TwoAxisListingFilterIntegrationTest extends TestCase
{
    private function twoAxisType(): EntityType
    {
        return new EntityType(
            id: 'teaching',
            label: 'Teaching',
            class: ContentEntityBase::class,
            keys: [
                'id'               => 'id',
                'uuid'             => 'uuid',
                'revision'         => 'revision_id',
                'langcode'         => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            revisionable: true,
            translatable: true,
        );
    }

    private function translatableOnlyType(): EntityType
    {
        return new EntityType(
            id: 'taxonomy_term',
            label: 'Term',
            class: ContentEntityBase::class,
            keys: [
                'id'               => 'id',
                'uuid'             => 'uuid',
                'langcode'         => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            revisionable: false,
            translatable: true,
        );
    }

    private function makeRow(string $id, string $langcode, string $title): EntityInterface
    {
        return new class ([
            'id'       => $id,
            'uuid'     => 'uuid-' . $id,
            'langcode' => $langcode,
            'title'    => $title,
        ], 'teaching', [
            'id'               => 'id',
            'uuid'             => 'uuid',
            'revision'         => 'revision_id',
            'langcode'         => 'langcode',
            'default_langcode' => 'default_langcode',
        ]) extends ContentEntityBase {
            public function __construct(array $values, string $entityTypeId, array $entityKeys)
            {
                parent::__construct($values, $entityTypeId, $entityKeys, []);
            }
        };
    }

    /**
     * @param array<string, array<string, EntityInterface>> $perLangcodeCurrentRevision
     */
    private function makeRepository(array $perLangcodeCurrentRevision): EntityRepositoryInterface
    {
        return new class ($perLangcodeCurrentRevision) implements EntityRepositoryInterface {
            /** @var array<string, array<string, EntityInterface>> */
            private array $map;

            /** @var list<array{0: string, 1: ?string}> */
            public array $findCalls = [];

            /** @param array<string, array<string, EntityInterface>> $map */
            public function __construct(array $map)
            {
                $this->map = $map;
            }

            public function create(array $values = []): EntityInterface
            {
                throw new \LogicException('create() not implemented in this test double');
            }

            public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
            {
                $this->findCalls[] = [$id, $langcode];

                return $this->map[$id][$langcode ?? '__default__'] ?? null;
            }

            public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
            {
                return [];
            }

            public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
            {
                return [];
            }

            public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface
            {
                throw new \LogicException('getQuery() not implemented in this test double');
            }

            public function save(EntityInterface $entity, bool $validate = true): int
            {
                return 0;
            }

            public function delete(EntityInterface $entity): void {}

            public function exists(string $id): bool
            {
                return true;
            }

            public function count(array $criteria = []): int
            {
                return 0;
            }

            public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
            {
                return null;
            }

            public function rollback(string $entityId, int $targetRevisionId): EntityInterface
            {
                throw new \LogicException('not used');
            }

            public function saveMany(array $entities, bool $validate = true): array
            {
                return [];
            }

            public function deleteMany(array $entities): int
            {
                return 0;
            }

            public function findTranslations(EntityInterface $entity): array
            {
                return [];
            }

            public function listRevisions(string $entityId): array
            {
                return [];
            }

            public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface
            {
                throw new \LogicException('not used');
            }

            public function loadPublishedRevision(string $entityId): ?EntityInterface
            {
                return null;
            }

            public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
            {
                throw new \LogicException('not used');
            }

            public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
            {
                throw new \LogicException('not used');
            }

            public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
            {
                throw new \LogicException('not used');
            }

            public function listTranslationRevisions(string $entityId, string $langcode): array
            {
                throw new \LogicException('not used');
            }
        };
    }

    #[Test]
    public function fr030FilterLangcodeFactoryProducesCanonicalFilterDefinition(): void
    {
        // FR-030 — Filter::langcode('oj') is the user-facing API. No new
        // ListingDefinition::langcode value-object field; the canonical
        // factory wins.
        $filter = Filter::langcode('oj');

        self::assertInstanceOf(FilterDefinition::class, $filter);
        self::assertSame('langcode', $filter->field);
        self::assertSame(Operator::EQ, $filter->op);
        self::assertSame('oj', $filter->value);
    }

    #[Test]
    public function fr033EffectiveContextsAutoInjectsLanguageContentForTwoAxisType(): void
    {
        // FR-033 — language.content (M-007 canonical token) is auto-injected
        // when the entity type is translatable. Two-axis types inherit this
        // unchanged; no parallel 'language.requested' token.
        $def = new ListingDefinition(
            id: 'teaching_recent',
            entityType: 'teaching',
            filters: [Filter::langcode('oj')],
        );

        $contexts = $def->effectiveContexts($this->twoAxisType());

        self::assertContains(ContextNames::LANGUAGE_CONTENT, $contexts);
        self::assertNotContains(
            'language.requested',
            $contexts,
            'Mission must not introduce a parallel language.requested context (FR-033).',
        );
    }

    #[Test]
    public function fr031ExcludesEntitiesWithoutTranslationRowForRequestedLangcode(): void
    {
        // FR-031 — Filter::langcode('oj') returns only entities whose
        // (entity_id, 'oj') translation row exists. The base findBy() query
        // typically returns candidate rows (e.g. by translation table join);
        // the resolver re-reads each candidate and drops those that have no
        // 'oj' translation. Below: rows 1 and 2 are candidates; only row 1
        // has an 'oj' translation row.
        $row1En = $this->makeRow('1', 'en', 'eng-1');
        $row2En = $this->makeRow('2', 'en', 'eng-2');

        $row1Oj = $this->makeRow('1', 'oj', 'gikinoo\'amaagewin r2');
        // No (2, 'oj') in the map.

        $repository = $this->makeRepository([
            '1' => ['oj' => $row1Oj],
        ]);

        $resolver = new TwoAxisFilterResolver($repository, $this->twoAxisType());

        $resolved = $resolver->resolveForLangcode([$row1En, $row2En], 'oj');

        self::assertCount(1, $resolved);
        self::assertSame($row1Oj, $resolved[0]);
    }

    #[Test]
    public function fr033aRowsAreMaterialisedAtPerLangcodeCurrentRevision(): void
    {
        // FR-033a — the genuinely new contract: re-read each candidate row
        // at the per-(entity, langcode) current revision, NOT the entity-
        // level "primary current revision." Verified by asserting the
        // repository's find() is invoked with the requested langcode and
        // the resolved row is the langcode-specific revision (different
        // title than the input row carrying the en revision).
        $row1En = $this->makeRow('1', 'en', 'eng-1');
        $row1OjR2 = $this->makeRow('1', 'oj', 'gikinoo\'amaagewin r2');

        $repository = $this->makeRepository([
            '1' => ['oj' => $row1OjR2],
        ]);

        $resolver = new TwoAxisFilterResolver($repository, $this->twoAxisType());
        $resolved = $resolver->resolveForLangcode([$row1En], 'oj');

        self::assertCount(1, $resolved);
        self::assertSame($row1OjR2, $resolved[0]);
        self::assertSame('gikinoo\'amaagewin r2', $resolved[0]->get('title'));
        self::assertSame([['1', 'oj']], $repository->findCalls);
    }

    #[Test]
    public function singleAxisTranslatableTypeBypassesTwoAxisRead(): void
    {
        // Substrate audit: the resolver is a no-op for translatable-only
        // (no revisions) entity types — M-007's existing pipeline already
        // routes Filter::langcode() correctly for them and re-reading would
        // be wasted work.
        $row1En = $this->makeRow('1', 'en', 'eng');
        $repository = $this->makeRepository([]);

        $resolver = new TwoAxisFilterResolver($repository, $this->translatableOnlyType());

        self::assertFalse($resolver->appliesToEntityType());
        self::assertSame([$row1En], $resolver->resolveForLangcode([$row1En], 'oj'));
        self::assertSame([], $repository->findCalls);
    }

    #[Test]
    public function entityTypeKeysDoNotIntroduceLangcodeListingDefinitionField(): void
    {
        // FR-030 negative test — the ListingDefinition surface MUST NOT grow
        // a `langcode` value-object field. We assert by inspecting the
        // public constructor parameters via reflection.
        $reflection = new \ReflectionClass(ListingDefinition::class);
        $params = $reflection->getConstructor()?->getParameters() ?? [];
        $paramNames = array_map(fn(\ReflectionParameter $p): string => $p->getName(), $params);

        self::assertNotContains(
            'langcode',
            $paramNames,
            'FR-030: ListingDefinition must not grow a `langcode` field; Filter::langcode() is canonical.',
        );
    }

    /**
     * Sanity check that the EntityTypeInterface contract used by the resolver
     * is the one we expect — two-axis is `isRevisionable() && isTranslatable()`.
     */
    #[Test]
    public function twoAxisDetectionUsesEntityTypeInterfaceFlags(): void
    {
        $entityType = $this->twoAxisType();
        self::assertInstanceOf(EntityTypeInterface::class, $entityType);
        self::assertTrue($entityType->isRevisionable());
        self::assertTrue($entityType->isTranslatable());

        $single = $this->translatableOnlyType();
        self::assertFalse($single->isRevisionable());
        self::assertTrue($single->isTranslatable());
    }
}
