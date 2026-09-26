<?php

declare(strict_types=1);

/**
 * Audit probe for GRP-PERSIST-001 (docs/audits/packages/groups.md; #2762).
 *
 * GroupMembershipService::addMember() looks for an existing
 * (group_membership, user, group) row, then creates one if it found none. The
 * find runs outside the write's transaction and no unique key covers the
 * triple, so a second addMember() that lands between another call's find and
 * its insert leaves two live rows for one membership.
 *
 * The probe forces that interleaving deterministically in one process: a
 * PRE_SAVE listener runs the second addMember() inside the first call's
 * window. The audit also reproduced the race without forcing it (separate PHP
 * processes meeting at a barrier, and against a consumer's installed split);
 * that evidence is recorded in docs/audits/packages/groups.ledger.json.
 *
 * Synthetic: the real relationship, group and user entity types and
 * repositories on a throwaway SQLite file from the packages/testing
 * TemporarySqliteDatabase utility, with relationship's PRE_SAVE validation
 * wired as in production. No kernel, no application database.
 *
 * Run from the repository root:
 *
 *   php tests/Fixtures/Audits/Groups/GRP-PERSIST-001-duplicate-membership.php
 *
 * Prints one JSON line per case. Exit 0 means the code behaves as the audit
 * records (two live rows); exit 1 means it no longer does, which is what the
 * #2762 repair should produce, with the control still at one row.
 */

use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Groups\GroupRelationshipTypes;
use Waaseyaa\Groups\GroupsServiceProvider;
use Waaseyaa\Groups\Membership\GroupMembershipService;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipPreSaveListener;
use Waaseyaa\Relationship\RelationshipServiceProvider;
use Waaseyaa\Relationship\RelationshipValidator;
use Waaseyaa\Testing\Database\TemporarySqliteDatabase;
use Waaseyaa\User\User;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

/**
 * @return array{0: EntityTypeManager, 1: EventDispatcher, 2: DBALDatabase}
 */
function groupsPersist001Stack(TemporarySqliteDatabase $temporary): array
{
    $database = $temporary->database();
    if (!$database instanceof DBALDatabase) {
        throw new RuntimeException('TemporarySqliteDatabase must provide a DBALDatabase');
    }
    EntityType::clearFromClassCache();
    $dispatcher = new EventDispatcher();
    $registry = new FieldDefinitionRegistry();
    $resolver = new SingleConnectionResolver($database);
    $manager = new EntityTypeManager(
        $dispatcher,
        null,
        static function (string $entityTypeId, EntityTypeInterface $definition) use ($database, $dispatcher, $registry, $resolver) {
            new SqlSchemaHandler($definition, $database, $registry)->ensureTable();

            return V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $definition,
                new SqlStorageDriver($resolver, $definition->getKeys()['id'] ?? 'id', null, $registry),
                $dispatcher,
                database: $database,
                fieldRegistry: $registry,
            );
        },
        fieldRegistry: $registry,
    );
    ContentEntityBase::setFieldRegistry($registry);
    ContentEntityBase::setEntityTypeManager($manager);
    foreach ([new RelationshipServiceProvider(), new GroupsServiceProvider()] as $provider) {
        $provider->register();
        foreach ($provider->getEntityTypes() as $type) {
            $manager->registerEntityType($type);
        }
    }
    $manager->registerEntityType(EntityType::fromClass(User::class, group: 'people'));
    $dispatcher->addListener(EntityEvents::PRE_SAVE->value, new RelationshipPreSaveListener(new RelationshipValidator($manager)));

    $users = $manager->getRepository('user');
    $user = $users->create(['uid' => 7, 'name' => 'user-7', 'mail' => 'user-7@example.test', 'status' => true]);
    $user->enforceIsNew();
    $users->save($user, validate: false);
    $groups = $manager->getRepository('group');
    $group = $groups->create(['gid' => 1, 'type' => 'department', 'name' => 'Department', 'status' => 1]);
    $group->enforceIsNew();
    $groups->save($group, validate: false);

    return [$manager, $dispatcher, $database];
}

function groupsPersist001LiveRows(EntityTypeManager $manager): int
{
    $query = $manager->getRepository('relationship')->getQuery();
    // Probe read of the audited rows; no account in scope.
    $query->accessCheck(false);
    $query->condition('relationship_type', GroupRelationshipTypes::MEMBERSHIP);
    $query->condition('from_entity_type', 'user');
    $query->condition('from_entity_id', '7');
    $query->condition('to_entity_type', 'group');
    $query->condition('to_entity_id', '1');
    $query->condition('status', 1);

    return count($query->execute());
}

$ok = true;

// Control: two sequential addMember() calls leave one live row.
$temporary = new TemporarySqliteDatabase();
[$manager] = groupsPersist001Stack($temporary);
$service = new GroupMembershipService($manager);
$service->addMember(7, '1');
$service->addMember(7, '1');
$control = groupsPersist001LiveRows($manager);
echo json_encode(['case' => 'control.sequential', 'live_rows' => $control], JSON_THROW_ON_ERROR), "\n";
$ok = $ok && $control === 1;
ContentEntityBase::setEntityTypeManager(null);
unset($temporary);

// Interleaved: the second call runs between the first call's find and insert.
$temporary = new TemporarySqliteDatabase();
[$manager, $dispatcher] = groupsPersist001Stack($temporary);
$service = new GroupMembershipService($manager);
$fired = false;
$dispatcher->addListener(EntityEvents::PRE_SAVE->value, static function (EntityEvent $event) use (&$fired, $service): void {
    if ($fired || !$event->entity instanceof Relationship || !$event->entity->isNew()) {
        return;
    }
    $fired = true;
    $service->addMember(7, '1');
});
$service->addMember(7, '1');
$interleaved = groupsPersist001LiveRows($manager);
echo json_encode(['case' => 'race.interleaved', 'live_rows' => $interleaved, 'both_calls_returned' => true], JSON_THROW_ON_ERROR), "\n";
$ok = $ok && $interleaved === 2;
ContentEntityBase::setEntityTypeManager(null);
ContentEntityBase::setFieldRegistry(null);
unset($temporary);

exit($ok ? 0 : 1);
