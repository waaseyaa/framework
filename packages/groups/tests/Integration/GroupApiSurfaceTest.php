<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiRouteProvider;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Groups\Group;
use Waaseyaa\Groups\GroupAccessPolicy;
use Waaseyaa\Groups\GroupType;
use Waaseyaa\Groups\GroupsServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\User\AnonymousUser;

/**
 * Pins the deliberate group/group_type JSON:API surface tracked by #1871.
 */
#[CoversNothing]
final class GroupApiSurfaceTest extends TestCase
{
    #[Test]
    public function groupAndGroupTypeAreDeliberatelyApiExposedAndDiscoverable(): void
    {
        $types = $this->registeredTypes();

        foreach (['group', 'group_type'] as $entityTypeId) {
            self::assertArrayHasKey($entityTypeId, $types);
            self::assertTrue($types[$entityTypeId]->isApiExposed());
            self::assertTrue($types[$entityTypeId]->isDiscoverable());
        }
    }

    #[Test]
    public function genericJsonApiRoutesExistForBothGroupTypes(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        foreach ($this->registeredTypes() as $type) {
            $manager->registerEntityType($type);
        }
        $router = new WaaseyaaRouter();

        new JsonApiRouteProvider($manager)->registerRoutes($router);

        foreach (['group', 'group_type'] as $entityTypeId) {
            self::assertNotNull($router->getRouteCollection()->get("api.{$entityTypeId}.index"));
            self::assertNotNull($router->getRouteCollection()->get("api.{$entityTypeId}.store"));
            self::assertNull($router->getRouteCollection()->get("api.{$entityTypeId}.not_exposed"));
        }
    }

    #[Test]
    public function administerGroupsPermissionGatesEntityCrud(): void
    {
        $policy = new GroupAccessPolicy();
        $handler = new EntityAccessHandler([$policy]);
        $administrator = new AnonymousUser(permissions: [GroupAccessPolicy::ADMIN_PERMISSION]);
        $plainAccount = new AnonymousUser(permissions: ['some unrelated permission']);

        foreach ([$this->makeGroup(), $this->makeGroupType()] as $entity) {
            foreach (['view', 'update', 'delete'] as $operation) {
                self::assertTrue($handler->check($entity, $operation, $administrator)->isAllowed());
                self::assertTrue($handler->check($entity, $operation, $plainAccount)->isForbidden());
            }
        }
        foreach (['group' => 'business', 'group_type' => ''] as $entityTypeId => $bundle) {
            self::assertTrue($handler->checkCreateAccess($entityTypeId, $bundle, $administrator)->isAllowed());
            self::assertTrue($handler->checkCreateAccess($entityTypeId, $bundle, $plainAccount)->isForbidden());
        }
        self::assertTrue($policy->appliesTo('group'));
        self::assertTrue($policy->appliesTo('group_type'));
        self::assertFalse($policy->appliesTo('node'));
    }

    /**
     * @return array<string, EntityTypeInterface>
     */
    private function registeredTypes(): array
    {
        $provider = new GroupsServiceProvider();
        $provider->register();

        $types = [];
        foreach ($provider->getEntityTypes() as $type) {
            $types[$type->id()] = $type;
        }

        return $types;
    }

    private function makeGroup(): Group
    {
        return new Group([
            'uuid' => 'uuid-group-api-surface-test',
            'type' => 'business',
            'name' => 'Group API Surface Test',
            'langcode' => 'en',
        ]);
    }

    private function makeGroupType(): GroupType
    {
        return new GroupType([
            'id' => 'business',
            'label' => 'Business',
        ]);
    }
}
