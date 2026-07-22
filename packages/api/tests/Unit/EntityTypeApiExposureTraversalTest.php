<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\EntityTypeApiExposurePolicy;
use Waaseyaa\Api\Controller\SchemaController;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

final class EntityTypeApiExposureTraversalTest extends TestCase
{
    #[Test]
    public function unexposed_and_unknown_include_paths_are_byte_identical_before_target_access(): void
    {
        [$controller, $targetResolutions] = $this->controller();

        $unexposed = json_encode($controller->index('article', ['include' => 'author'])->toArray(), JSON_THROW_ON_ERROR);
        $unknown = json_encode($controller->index('article', ['include' => 'missing'])->toArray(), JSON_THROW_ON_ERROR);

        self::assertSame($unknown, $unexposed);
        self::assertSame(0, $targetResolutions());
    }

    #[Test]
    public function show_rejects_unknown_and_unexposed_include_paths_before_serialization(): void
    {
        [$controller, $targetResolutions] = $this->controller();

        $unexposed = $controller->show('article', 1, ['include' => 'author']);
        $unknown = $controller->show('article', 1, ['include' => 'missing']);

        self::assertSame(400, $unexposed->statusCode);
        self::assertSame($unknown->toArray(), $unexposed->toArray());
        self::assertSame(0, $targetResolutions());
    }

    #[Test]
    public function relationship_filter_and_sort_paths_are_indistinguishable_from_nonexistent_paths(): void
    {
        [$controller, $targetResolutions] = $this->controller();

        $unexposedFilter = $controller->index('article', ['filter' => ['author.name' => 'x']])->toArray();
        $unknownFilter = $controller->index('article', ['filter' => ['missing.name' => 'x']])->toArray();
        self::assertSame($unknownFilter, $unexposedFilter);

        $unexposedSort = $controller->index('article', ['sort' => 'author.name'])->toArray();
        $unknownSort = $controller->index('article', ['sort' => 'missing.name'])->toArray();
        self::assertSame($unknownSort, $unexposedSort);
        self::assertSame(0, $targetResolutions());
    }

    #[Test]
    public function exposed_resource_omits_reference_values_targeting_an_unexposed_type(): void
    {
        [$controller] = $this->controller();

        $document = $controller->show('article', 1)->toArray();
        $encoded = json_encode($document, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('related', $document['data']['attributes']);
        self::assertArrayNotHasKey('hero', $document['data']['attributes']);
        self::assertArrayNotHasKey('summary', $document['data']['attributes']);
        self::assertStringNotContainsString('suppressed-user-id', $encoded);
        self::assertStringNotContainsString('orphan-target-id', $encoded);
        self::assertStringNotContainsString('conflicting-target-id', $encoded);
        self::assertStringNotContainsString('"type":"user"', $encoded);
    }

    #[Test]
    public function exposed_schema_omits_target_type_metadata_for_an_unexposed_reference(): void
    {
        [, , $manager, $policy] = $this->controller();
        $controller = new SchemaController(
            $manager,
            new SchemaPresenter(exposurePolicy: $policy),
            exposurePolicy: $policy,
        );

        $schema = $controller->show('article')->toArray()['meta']['schema'];

        self::assertArrayNotHasKey('x-target-type', $schema['properties']['author']);
        self::assertStringNotContainsString('"x-target-type":"user"', json_encode($schema, JSON_THROW_ON_ERROR));
    }

    /** @return array{JsonApiController, \Closure(): int, EntityTypeManager, EntityTypeApiExposurePolicy} */
    private function controller(): array
    {
        $articleStorage = new InMemoryEntityStorage('article');
        $targetResolutions = 0;
        $manager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $articleStorage,
            function (string $entityTypeId) use ($articleStorage, &$targetResolutions): InMemoryEntityRepository {
                if ($entityTypeId === 'user') {
                    ++$targetResolutions;
                }
                return new InMemoryEntityRepository($articleStorage);
            },
        );
        $manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
            _fieldDefinitions: [
                'author' => [
                    'type' => 'entity_reference',
                    'settings' => ['target_entity_type_id' => 'user'],
                ],
                'related' => [
                    'type' => 'entity_reference',
                    'settings' => ['target_entity_type_id' => 'user'],
                ],
                'hero' => [
                    'type' => 'entity_reference',
                ],
                'summary' => [
                    'type' => 'entity_reference',
                    'settings' => [
                        'target_entity_type_id' => 'user',
                        'target_type' => 'article',
                    ],
                ],
            ],
        ));
        $manager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));
        $policy = EntityTypeApiExposurePolicy::fromConfig($manager, [
            'api' => ['entity_type_allowlist' => ['article']],
        ]);
        $article = $articleStorage->create([
            'uuid' => 'article-uuid',
            'title' => 'Article',
            'related' => 'suppressed-user-id',
            'hero' => 'orphan-target-id',
            'summary' => 'conflicting-target-id',
        ]);
        $articleStorage->save($article);
        return [
            new JsonApiController(
                $manager,
                new ResourceSerializer($manager, exposurePolicy: $policy),
                exposurePolicy: $policy,
            ),
            function () use (&$targetResolutions): int {
                return $targetResolutions;
            },
            $manager,
            $policy,
        ];
    }
}
