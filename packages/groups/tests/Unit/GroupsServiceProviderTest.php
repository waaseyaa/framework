<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Groups\Group;
use Waaseyaa\Groups\GroupsServiceProvider;
use Waaseyaa\Groups\GroupType;
use Waaseyaa\Groups\StaffDirectory\CapabilityScopedStaffDirectoryAccessPolicy;
use Waaseyaa\Groups\StaffDirectory\StaffDirectoryReaderInterface;

/**
 * Docblock @covers is indexed by tools/audit/GenerateLayerAudit.php; #[CoversClass] alone is not.
 *
 * @covers \Waaseyaa\Groups\GroupsServiceProvider
 * @covers \Waaseyaa\Groups\Group
 * @covers \Waaseyaa\Groups\GroupType
 */
#[CoversClass(GroupsServiceProvider::class)]
final class GroupsServiceProviderTest extends TestCase
{
    #[Test]
    public function declares_the_optional_staff_policy_and_reader_contract(): void
    {
        $provider = new GroupsServiceProvider();
        $provider->register();
        self::assertArrayHasKey(StaffDirectoryReaderInterface::class, $provider->getBindings());

        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertContains(
            CapabilityScopedStaffDirectoryAccessPolicy::class,
            $manifest['extra']['waaseyaa']['policies'] ?? [],
        );
    }

    #[Test]
    public function registersGroupAndGroupType(): void
    {
        $provider = new GroupsServiceProvider();
        $provider->register();

        $entityTypes = $provider->getEntityTypes();

        self::assertCount(2, $entityTypes);

        $ids = array_map(static fn($t) => $t->id(), $entityTypes);
        self::assertContains('group', $ids);
        self::assertContains('group_type', $ids);

        $byId = [];
        foreach ($entityTypes as $t) {
            $byId[$t->id()] = $t;
        }
        self::assertSame(Group::class, $byId['group']->getClass());
        self::assertSame(GroupType::class, $byId['group_type']->getClass());
    }

    #[Test]
    public function groupIsMultiBundleKeyedByGidTypeName(): void
    {
        $provider = new GroupsServiceProvider();
        $provider->register();

        $group = null;
        foreach ($provider->getEntityTypes() as $t) {
            if ($t->id() === 'group') {
                $group = $t;
                break;
            }
        }
        self::assertNotNull($group);
        self::assertSame('group_type', $group->getBundleEntityType());

        $keys = $group->getKeys();
        self::assertSame('gid', $keys['id']);
        self::assertSame('uuid', $keys['uuid']);
        self::assertSame('type', $keys['bundle']);
        self::assertSame('name', $keys['label']);
        self::assertSame('langcode', $keys['langcode']);
    }

    #[Test]
    public function groupShipsWithUniversalDataStoredCoreFieldsOnly(): void
    {
        $provider = new GroupsServiceProvider();
        $provider->register();

        $group = null;
        foreach ($provider->getEntityTypes() as $t) {
            if ($t->id() === 'group') {
                $group = $t;
                break;
            }
        }
        self::assertNotNull($group);

        // Bundle fields are still 100% consumer-defined via
        // EntityTypeManager::addBundleFields(); the only core fields shipped
        // are the FieldStorage::Data universals so registry-aware queries can
        // resolve `status`/`created_at`/`updated_at` via json_extract.
        $fieldDefs = $group->getFieldDefinitions();
        self::assertSame(
            ['status', 'created_at', 'updated_at', 'members_can_view_directory'],
            array_keys($fieldDefs),
        );
        foreach ($fieldDefs as $def) {
            self::assertSame(\Waaseyaa\Field\FieldStorage::Data, $def['stored']);
        }
        self::assertFalse($fieldDefs['members_can_view_directory']['default']);
        self::assertSame(
            \Waaseyaa\Entity\FieldReadLevel::Protected,
            $fieldDefs['members_can_view_directory']['read'],
        );
        self::assertTrue($fieldDefs['members_can_view_directory']['settings']['authorizationInput']);
    }

    /**
     * Regression for #1388: every core FieldDefinition shipped by GroupsServiceProvider
     * MUST declare `targetEntityTypeId` matching its owning EntityType id, otherwise
     * `FieldDefinitionRegistry::registerCoreFields()` rejects the bundle at registration
     * time and the kernel cannot register `group_type`.
     */
    #[Test]
    public function group_type_field_definitions_declare_target_entity_type_id(): void
    {
        $provider = new GroupsServiceProvider();
        $provider->register();

        $groupType = null;
        foreach ($provider->getEntityTypes() as $t) {
            if ($t->id() === 'group_type') {
                $groupType = $t;
                break;
            }
        }
        self::assertNotNull($groupType, 'GroupsServiceProvider must register the group_type entity type.');

        $fieldDefs = $groupType->getFieldDefinitions();
        self::assertNotSame([], $fieldDefs, 'group_type must ship at least the description core field.');
        self::assertArrayHasKey('description', $fieldDefs, '#1388: description must be a core field on group_type.');

        foreach ($fieldDefs as $name => $def) {
            self::assertInstanceOf(
                FieldDefinitionInterface::class,
                $def,
                sprintf('group_type field "%s" must be a FieldDefinitionInterface instance.', $name),
            );
            self::assertSame(
                'group_type',
                $def->getTargetEntityTypeId(),
                sprintf(
                    '#1388: group_type core field "%s" must declare targetEntityTypeId "group_type"; '
                    . 'got "%s". An empty value will be rejected by FieldDefinitionRegistry.',
                    $name,
                    $def->getTargetEntityTypeId(),
                ),
            );
        }
    }
}
