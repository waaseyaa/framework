<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * R14 (audit A11 class-mate of R13 WP1): JsonApiController::index() applied
 * caller-supplied filter/sort field names as raw storage conditions with only
 * entity-level {@see JsonApiController::accessFilteredTotal()} recomputation.
 *
 * The structural allowlist ({@see JsonApiController::validateQueryFields()},
 * added after "audit R2 WP1") rejects unknown / ALWAYS_INTERNAL / `internal`-flagged
 * fields, but a field gated ONLY by a dynamic {@see FieldAccessPolicyInterface}
 * (e.g. a classification/clearance field with no static `internal` setting) is
 * structurally normal: it passes the allowlist and becomes a filter-presence /
 * meta.total oracle for a caller who may list the type and view its rows but
 * lacks the field's clearance.
 *
 * This test drives that exact scenario: `secret` is a DECLARED, non-internal
 * field that the field-access policy forbids for 'view', while entity-level
 * 'view' is allowed (the caller can list and read the rows). Filtering or
 * sorting on `secret` must yield NO field-derived signal — not in the row set
 * and not in meta.total — after the fix. The credential-field floor
 * (ALWAYS_INTERNAL_FIELDS) must stay blocked outright.
 */
#[CoversClass(JsonApiController::class)]
final class JsonApiControllerFieldFilterOracleTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private EntityTypeManager $entityTypeManager;
    private JsonApiController $controller;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');
        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
            fn() => new InMemoryEntityRepository($this->storage),
        );
        // `secret` and `title` are DECLARED, non-internal fields (they pass the
        // structural allowlist). Only the field-access policy below restricts
        // `secret` — the R14 field class the structural gate cannot express.
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            _fieldDefinitions: [
                'title' => ['type' => 'string', 'label' => 'Title'],
                'secret' => ['type' => 'string', 'label' => 'Clearance-gated field'],
            ],
        ));

        // Entity-level view: ALLOWED (the caller can list and read rows).
        // Field-level view of `secret`: FORBIDDEN (lacks clearance).
        $policy = new class () implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'article';
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($fieldName === 'secret' && $operation === 'view') {
                    return AccessResult::forbidden('No view access to secret');
                }

                return AccessResult::neutral();
            }
        };

        $this->controller = new JsonApiController(
            $this->entityTypeManager,
            new ResourceSerializer($this->entityTypeManager),
            new EntityAccessHandler([$policy]),
            $this->createMock(AccountInterface::class),
        );
    }

    private function seed(): void
    {
        // Two rows carry the probed value, two carry another — the population an
        // attacker would binary-search over.
        $this->save(['title' => 'A', 'secret' => 'classified']);
        $this->save(['title' => 'B', 'secret' => 'classified']);
        $this->save(['title' => 'C', 'secret' => 'public']);
        $this->save(['title' => 'D', 'secret' => 'public']);
    }

    private function save(array $values): void
    {
        $entity = $this->storage->create($values);
        $this->storage->save($entity);
    }

    // --- Exploit: filter on a view-forbidden declared field is an oracle ---

    #[Test]
    public function filteringOnViewForbiddenFieldLeaksNoPresenceSignal(): void
    {
        $this->seed();

        $doc = $this->controller->index('article', ['filter' => ['secret' => 'classified']]);
        $array = $doc->toArray();

        // meta.total must not reveal how many rows carry the probed clearance
        // value — that count is precisely the field the caller may not read.
        $this->assertSame(0, $array['meta']['total'], 'meta.total must not leak the count of rows matching a view-forbidden filter field');
        $this->assertCount(0, $array['data'], 'no row may surface from a view-forbidden filter field');
    }

    #[Test]
    public function differentForbiddenFilterValuesAreIndistinguishable(): void
    {
        $this->seed();

        $matchProbe = $this->controller->index('article', ['filter' => ['secret' => 'classified']])->toArray();
        $missProbe = $this->controller->index('article', ['filter' => ['secret' => 'does-not-exist']])->toArray();

        // Value-independent exclusion: a present value and an absent value must
        // return the identical (empty) shape, so no comparison leaks a bit.
        $this->assertSame($matchProbe['meta']['total'], $missProbe['meta']['total']);
        $this->assertSame(0, $matchProbe['meta']['total']);
    }

    #[Test]
    public function sortingOnViewForbiddenFieldIsRejected(): void
    {
        $this->seed();

        // A sort on a field the caller cannot read is rejected outright: storage
        // sort/pagination run before the value-independent drop, so a forbidden
        // row would otherwise still occupy an observable pagination rank.
        $doc = $this->controller->index('article', ['sort' => 'secret']);

        $this->assertSame(400, $doc->statusCode, 'sorting by a view-forbidden field must be rejected, never ordered');
        $this->assertStringContainsString('secret', $doc->toArray()['errors'][0]['detail']);
    }

    #[Test]
    public function paginatedSortOnForbiddenFieldAcrossOffsetsLeaksNoOrdering(): void
    {
        // The mixed per-row case the single-page test does not reach: `secret`
        // is view-Forbidden only on SOME rows (those whose title starts with
        // 'H'). Pre-fix, sort=secret + limit=1 across offsets produced an
        // empty-vs-populated page pattern that reconstructed the hidden values'
        // sort ranks (adversarial-review PoC). The sort must now be rejected at
        // EVERY offset, value-independently, so no rank slot is observable.
        $controller = $this->controllerWithPerRowSecretPolicy();
        $this->save(['title' => 'Anchor10', 'secret' => '10']);
        $this->save(['title' => 'Hidden15', 'secret' => '15']);
        $this->save(['title' => 'Hidden20', 'secret' => '20']);
        $this->save(['title' => 'Anchor30', 'secret' => '30']);

        foreach ([0, 1, 2, 3] as $offset) {
            $doc = $controller->index('article', ['sort' => 'secret', 'page' => ['offset' => $offset, 'limit' => 1]]);
            $this->assertSame(
                400,
                $doc->statusCode,
                "offset {$offset}: a sort touching a per-row-forbidden field must be rejected, not paginated",
            );
        }
    }

    private function controllerWithPerRowSecretPolicy(): JsonApiController
    {
        // Entity-level view ALLOWED for all; field 'secret' Forbidden only on
        // rows whose title starts with 'H' — a per-row classification split.
        $policy = new class () implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'article';
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($fieldName === 'secret' && $operation === 'view' && str_starts_with((string) $entity->get('title'), 'H')) {
                    return AccessResult::forbidden('No view access to secret on this row');
                }

                return AccessResult::neutral();
            }
        };

        return new JsonApiController(
            $this->entityTypeManager,
            new ResourceSerializer($this->entityTypeManager),
            new EntityAccessHandler([$policy]),
            $this->createMock(AccountInterface::class),
        );
    }

    // --- Positive control: readable fields still filter/sort normally ---

    #[Test]
    public function filteringOnReadableFieldStillWorks(): void
    {
        $this->seed();

        $doc = $this->controller->index('article', ['filter' => ['title' => 'A']]);
        $array = $doc->toArray();

        $this->assertSame(1, $array['meta']['total']);
        $this->assertCount(1, $array['data']);
    }

    #[Test]
    public function sortingOnReadableFieldStillWorks(): void
    {
        $this->seed();

        // A sort on a field the caller CAN read is never rejected — no
        // availability regression from the forbidden-sort guard.
        $doc = $this->controller->index('article', ['sort' => '-title']);
        $array = $doc->toArray();

        $this->assertSame(200, $doc->statusCode);
        $this->assertSame(4, $array['meta']['total']);
        $this->assertSame('D', $array['data'][0]['attributes']['title']);
    }

    // --- Regression: credential floor stays blocked outright ---

    #[Test]
    public function credentialFieldFilterStaysRejected(): void
    {
        $this->seed();

        $doc = $this->controller->index('article', ['filter' => ['password_hash' => 'x']]);

        $this->assertSame(400, $doc->statusCode, 'a credential-field filter must be rejected by the structural floor, never evaluated');
    }
}
