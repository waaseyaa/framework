<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase7;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Menu\MenuLink;

#[CoversNothing]
final class MenuLinkObjectTargetJsonApiTest extends TestCase
{
    #[Test]
    public function titleOnlyPatchPreservesObjectTargetReference(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = EntityType::fromClass(
            MenuLink::class,
            bundleEntityType: 'menu',
            group: 'structure',
        );
        new SqlSchemaHandler($entityType, $database)->ensureTable();

        $eventDispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($database);
        $entityTypeManager = new EntityTypeManager(
            $eventDispatcher,
            repositoryFactory: static fn(string $_id, EntityTypeInterface $type): EntityRepository => \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $type,
                new SqlStorageDriver($resolver),
                $eventDispatcher,
                database: $database,
            ),
        );
        $entityTypeManager->registerEntityType($entityType);
        $controller = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
        );

        $created = $controller->store('menu_link', [
            'data' => [
                'type' => 'menu_link',
                'attributes' => [
                    'title' => 'About',
                    'menu_name' => 'main',
                    'url' => '',
                    'target_entity_type' => 'node',
                    'target_entity_id' => '42',
                ],
            ],
        ]);

        self::assertSame(201, $created->statusCode, json_encode($created->toArray(), JSON_THROW_ON_ERROR));
        $createdResource = $created->toArray()['data'];

        $updated = $controller->update('menu_link', 1, [
            'data' => [
                'type' => 'menu_link',
                'id' => $createdResource['id'],
                'attributes' => [
                    'title' => 'Our Nation',
                ],
            ],
        ]);

        self::assertSame(200, $updated->statusCode);
        self::assertSame('Our Nation', $updated->toArray()['data']['attributes']['title']);

        $reloaded = $controller->show('menu_link', 1)->toArray()['data']['attributes'];
        self::assertSame('Our Nation', $reloaded['title']);
        self::assertSame('node', $reloaded['target_entity_type']);
        self::assertSame('42', $reloaded['target_entity_id']);
        self::assertSame('', $reloaded['url']);
    }
}
