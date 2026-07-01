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
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Pins C-26: on the authenticated (access handler + account bound) collection
 * path, meta.total must equal the access-filtered total ACROSS all pages — not
 * the size of the current page, and not the open-by-default storage COUNT.
 */
#[CoversClass(JsonApiController::class)]
final class JsonApiControllerMetaTotalTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private EntityTypeManager $entityTypeManager;
    private ResourceSerializer $serializer;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');
        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
            fn() => new InMemoryEntityRepository($this->storage),
        );
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        ));
        $this->serializer = new ResourceSerializer($this->entityTypeManager);
    }

    #[Test]
    public function metaTotalIsAccessFilteredTotalAcrossPagesNotPageSize(): void
    {
        // 6 visible + 4 hidden = 10 rows. View is granted (isAllowed) only when
        // the title starts with "Visible"; otherwise Neutral, which under
        // deny-by-default entity-level semantics is NOT visible.
        for ($i = 1; $i <= 6; $i++) {
            $this->createAndSaveEntity(['title' => "Visible {$i}"]);
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->createAndSaveEntity(['title' => "Hidden {$i}"]);
        }

        $controller = $this->controllerWithTitlePrefixViewPolicy('Visible');

        // Page 1 of 3 (limit 2) — only 2 rows on the page.
        $doc = $controller->index('article', [
            'page' => ['offset' => '0', 'limit' => '2'],
        ]);
        $array = $doc->toArray();

        // The page returns at most the page size of VISIBLE rows.
        $this->assertCount(2, $array['data']);
        foreach ($array['data'] as $resource) {
            $this->assertStringStartsWith('Visible', $resource['attributes']['title']);
        }

        // The bug: meta.total collapsed to the page size (2). The fix: it is the
        // true access-filtered total across all pages (6) — never 2 (page size),
        // never 10 (the open-by-default unfiltered/storage count).
        $this->assertSame(6, $array['meta']['total'], 'meta.total must be the cross-page access-filtered total, not the page size.');
    }

    #[Test]
    public function metaTotalIsStableAcrossPages(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->createAndSaveEntity(['title' => "Visible {$i}"]);
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->createAndSaveEntity(['title' => "Hidden {$i}"]);
        }

        $controller = $this->controllerWithTitlePrefixViewPolicy('Visible');

        // Last page (offset 4, limit 2) of the 6 visible rows — pagination here
        // is over the unfiltered candidate window, so the page may carry fewer
        // visible rows, but meta.total must not drift.
        $doc = $controller->index('article', [
            'page' => ['offset' => '4', 'limit' => '2'],
        ]);
        $array = $doc->toArray();

        $this->assertSame(6, $array['meta']['total'], 'meta.total must be page-invariant for a fixed query + account.');
    }

    private function controllerWithTitlePrefixViewPolicy(string $visiblePrefix): JsonApiController
    {
        $policy = new class($visiblePrefix) implements AccessPolicyInterface {
            public function __construct(private readonly string $visiblePrefix) {}

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'article';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation !== 'view') {
                    return AccessResult::neutral();
                }
                $title = (string) $entity->get('title');

                return str_starts_with($title, $this->visiblePrefix)
                    ? AccessResult::allowed('Title is visible.')
                    : AccessResult::neutral('No opinion — deny-by-default at entity level.');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
        };

        return new JsonApiController(
            $this->entityTypeManager,
            $this->serializer,
            new EntityAccessHandler([$policy]),
            $this->account(),
        );
    }

    private function account(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 1;
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    /**
     * @param array<string, mixed> $values
     */
    private function createAndSaveEntity(array $values): TestEntity
    {
        /** @var TestEntity $entity */
        $entity = $this->storage->create($values);
        $this->storage->save($entity);

        return $entity;
    }
}
