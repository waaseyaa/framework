<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase7;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Menu\Menu;
use Waaseyaa\Menu\MenuAccessPolicy;
use Waaseyaa\Menu\MenuServiceProvider;
use Waaseyaa\Tests\Support\AuthorizationPrincipalFactory;

/**
 * Anchors #2755: `Menu::$locked` was documented as "cannot be deleted" but no
 * runtime authority read it — MenuAccessPolicy granted every operation to
 * `administer menu` before ever consulting the flag, so a locked menu could
 * be deleted through the real JSON:API delete boundary exactly like an
 * unlocked one.
 *
 * Exercises the actual delete path (JsonApiController::destroy() ->
 * EntityAccessHandler::check() -> MenuAccessPolicy -> EntityRepository) with
 * a real SQLite-backed Menu, not a direct call to Menu::isLocked().
 */
#[CoversNothing]
final class MenuLockedDeleteJsonApiTest extends TestCase
{
    /**
     * @return array{0: JsonApiController, 1: EntityRepository}
     */
    private function makeController(): array
    {
        $database = DBALDatabase::createSqlite();

        // Pull the real production registration (including its
        // #2755 _fieldDefinitions) rather than re-declaring it here, so this
        // test tracks MenuServiceProvider instead of silently drifting from it.
        $provider = new MenuServiceProvider();
        $provider->register();
        $entityType = null;
        foreach ($provider->getEntityTypes() as $candidate) {
            if ($candidate->id() === 'menu') {
                $entityType = $candidate;
                break;
            }
        }
        self::assertNotNull($entityType, 'MenuServiceProvider must register the menu entity type.');

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

        $accessHandler = new EntityAccessHandler([new MenuAccessPolicy()]);
        $account = AuthorizationPrincipalFactory::authenticated(permissions: ['administer menu']);

        $controller = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
            $accessHandler,
            $account,
        );

        return [$controller, $entityTypeManager->getRepository('menu')];
    }

    #[Test]
    public function locked_menu_delete_is_refused_even_for_administer_menu(): void
    {
        [$controller, $repository] = $this->makeController();

        $menu = new Menu(['id' => 'main', 'label' => 'Main', 'locked' => true]);
        $repository->save($menu, validate: false);
        $id = $menu->id();
        self::assertNotNull($id);

        $result = $controller->destroy('menu', $id);

        self::assertSame(403, $result->statusCode, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertNotNull($repository->find((string) $id), 'A refused delete must leave the locked menu in place.');
    }

    #[Test]
    public function unlocked_menu_delete_still_succeeds_for_administer_menu(): void
    {
        [$controller, $repository] = $this->makeController();

        $menu = new Menu(['id' => 'footer', 'label' => 'Footer', 'locked' => false]);
        $repository->save($menu, validate: false);
        $id = $menu->id();
        self::assertNotNull($id);

        $result = $controller->destroy('menu', $id);

        self::assertSame(204, $result->statusCode);
        self::assertNull($repository->find((string) $id), 'An unlocked menu must still be deletable.');
    }
}
