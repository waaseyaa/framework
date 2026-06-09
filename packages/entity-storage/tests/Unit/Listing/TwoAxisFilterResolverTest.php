<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Listing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Listing\TwoAxisFilterResolver;

/**
 * Unit tests for {@see TwoAxisFilterResolver}.
 *
 * Validates the WP07 read-side glue contract for FR-031 (exclusion of
 * entities without a `(entity_id, langcode)` translation row) and FR-033a
 * (read at the langcode's current revision via the repository).
 */
#[CoversClass(TwoAxisFilterResolver::class)]
final class TwoAxisFilterResolverTest extends TestCase
{
    /**
     * Concrete (final) entity types used in tests. Their fixture nature is
     * captured by the inline factories below.
     */
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

    private function makeRow(string $id, string $langcode = 'en', string $title = ''): EntityInterface
    {
        return new class([
            'id'       => $id,
            'uuid'     => 'uuid-' . $id,
            'langcode' => $langcode,
            'title'    => $title === '' ? 'row-' . $id : $title,
        ], 'teaching', [
            'id'               => 'id',
            'uuid'             => 'uuid',
            'revision'         => 'revision_id',
            'langcode'         => 'langcode',
            'default_langcode' => 'default_langcode',
        ]) extends ContentEntityBase {
            // Override metadata bootstrap by providing the values inline.
            public function __construct(array $values, string $entityTypeId, array $entityKeys)
            {
                parent::__construct($values, $entityTypeId, $entityKeys, []);
            }
        };
    }

    /**
     * Build a stub repository whose `find($id, $langcode)` returns the entity
     * for `(id, langcode)` pairs supplied in `$map`, and `null` otherwise.
     *
     * @param array<string, array<string, EntityInterface>> $map id => langcode => entity
     */
    private function makeStubRepository(array $map): EntityRepositoryInterface
    {
        return new class($map) implements EntityRepositoryInterface {
            /** @var array<string, array<string, EntityInterface>> */
            private array $map;

            /** @var array<int, array{0: string, 1: ?string}> */
            public array $findCalls = [];

            /** @param array<string, array<string, EntityInterface>> $map */
            public function __construct(array $map)
            {
                $this->map = $map;
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

            public function save(EntityInterface $entity, bool $validate = true): int
            {
                return 0;
            }

            public function delete(EntityInterface $entity): void {}

            public function exists(string $id): bool
            {
                return false;
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

            public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
            {
                throw new \LogicException('not used');
            }

            public function saveTranslationRevision(string $entityId, string $langcode, array $values, ?string $log = null): int
            {
                throw new \LogicException('not used');
            }

            public function saveTranslationRevisions(string $entityId, array $byLangcode, ?string $log = null): array
            {
                throw new \LogicException('not used');
            }

            public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
            {
                throw new \LogicException('not used');
            }

            public function loadTranslationRevision(string $entityId, string $langcode, int $revisionId): ?EntityInterface
            {
                throw new \LogicException('not used');
            }

            public function loadTranslationTip(string $entityId, string $langcode): ?EntityInterface
            {
                throw new \LogicException('not used');
            }

            public function listTranslationRevisions(string $entityId, string $langcode): array
            {
                throw new \LogicException('not used');
            }

            public function translationLangcodes(string $entityId): array
            {
                throw new \LogicException('not used');
            }
        };
    }

    #[Test]
    public function resolverIsNoOpForSingleAxisType(): void
    {
        // FR-030 / FR-031 are vacuous on a translatable-only (no revisions)
        // entity type — the M-007 pipeline already returns the right rows.
        $row1 = $this->makeRow('1', 'en');
        $row2 = $this->makeRow('2', 'en');

        $repository = $this->makeStubRepository([]);
        $resolver = new TwoAxisFilterResolver($repository, $this->translatableOnlyType());

        self::assertFalse($resolver->appliesToEntityType());
        self::assertSame([$row1, $row2], $resolver->resolveForLangcode([$row1, $row2], 'oj'));
        self::assertSame([], $repository->findCalls, 'single-axis types MUST NOT trigger re-reads');
    }

    #[Test]
    public function emptyInputReturnsEmpty(): void
    {
        $repository = $this->makeStubRepository([]);
        $resolver = new TwoAxisFilterResolver($repository, $this->twoAxisType());

        self::assertSame([], $resolver->resolveForLangcode([], 'oj'));
    }

    #[Test]
    public function emptyLangcodeIsNoOp(): void
    {
        // An empty langcode is treated as "no two-axis routing requested";
        // we return the input rows unchanged rather than excluding everything.
        $row = $this->makeRow('1', 'en');
        $repository = $this->makeStubRepository([]);
        $resolver = new TwoAxisFilterResolver($repository, $this->twoAxisType());

        self::assertSame([$row], $resolver->resolveForLangcode([$row], ''));
    }

    #[Test]
    public function fr031ExcludesRowsWithoutTranslationForRequestedLangcode(): void
    {
        // Two rows are returned by the base listing query (e.g. via the
        // criteria-narrowed findBy()). Only one has an 'oj' translation;
        // the other is excluded per FR-031.
        $row1 = $this->makeRow('1', 'en');
        $row2 = $this->makeRow('2', 'en');

        $ojOf1 = $this->makeRow('1', 'oj', title: 'gikinoo\'amaagewin');
        // No (2, 'oj') row in the map -> repository->find(2, 'oj') returns null.

        $repository = $this->makeStubRepository([
            '1' => ['oj' => $ojOf1],
        ]);
        $resolver = new TwoAxisFilterResolver($repository, $this->twoAxisType());

        $result = $resolver->resolveForLangcode([$row1, $row2], 'oj');

        self::assertCount(1, $result, 'row without (id, "oj") translation MUST be excluded (FR-031)');
        self::assertSame($ojOf1, $result[0]);
    }

    #[Test]
    public function fr033aReadsAtLangcodeCurrentRevisionNotEntityPrimaryRevision(): void
    {
        // FR-033a — the resolver MUST re-read each surviving row via the
        // repository's langcode-aware find(), which routes through the
        // per-(entity, langcode) current-revision pointer (not the entity's
        // "primary current revision"). The stub asserts the langcode token
        // is propagated as-is.
        $row1 = $this->makeRow('1', 'en'); // input row carries the en revision
        $ojOf1 = $this->makeRow('1', 'oj', title: 'gikinoo\'amaagewin r2');

        $repository = $this->makeStubRepository([
            '1' => ['oj' => $ojOf1],
        ]);
        $resolver = new TwoAxisFilterResolver($repository, $this->twoAxisType());

        $result = $resolver->resolveForLangcode([$row1], 'oj');

        self::assertCount(1, $result);
        self::assertSame($ojOf1, $result[0], 'result MUST be the langcode-current row, not the original');
        self::assertSame([['1', 'oj']], $repository->findCalls, 'find() MUST be called with the requested langcode');
    }

    #[Test]
    public function preservesInputOrder(): void
    {
        $row1 = $this->makeRow('a', 'en');
        $row2 = $this->makeRow('b', 'en');
        $row3 = $this->makeRow('c', 'en');

        $ojA = $this->makeRow('a', 'oj');
        $ojC = $this->makeRow('c', 'oj');

        $repository = $this->makeStubRepository([
            'a' => ['oj' => $ojA],
            'c' => ['oj' => $ojC],
        ]);
        $resolver = new TwoAxisFilterResolver($repository, $this->twoAxisType());

        $result = $resolver->resolveForLangcode([$row1, $row2, $row3], 'oj');

        self::assertCount(2, $result);
        self::assertSame($ojA, $result[0]);
        self::assertSame($ojC, $result[1]);
    }

    #[Test]
    public function skipsRowsWithNullId(): void
    {
        // Defensive — rows without a persisted id cannot be re-read.
        $rowNoId = new class([], 'teaching', [
            'id' => 'id', 'uuid' => 'uuid', 'revision' => 'revision_id',
            'langcode' => 'langcode', 'default_langcode' => 'default_langcode',
        ]) extends ContentEntityBase {
            public function __construct(array $values, string $entityTypeId, array $entityKeys)
            {
                parent::__construct($values, $entityTypeId, $entityKeys, []);
            }
        };

        $repository = $this->makeStubRepository([]);
        $resolver = new TwoAxisFilterResolver($repository, $this->twoAxisType());

        self::assertSame([], $resolver->resolveForLangcode([$rowNoId], 'oj'));
        self::assertSame([], $repository->findCalls);
    }
}
