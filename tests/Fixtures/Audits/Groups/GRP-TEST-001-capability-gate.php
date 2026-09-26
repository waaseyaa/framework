<?php

declare(strict_types=1);

/**
 * Audit probe for GRP-TEST-001 (docs/audits/packages/groups.md).
 *
 * CapabilityScopedStaffDirectoryReadPolicy is discovered globally and applies
 * to every contextual User query, not only to StaffDirectoryReader. Its
 * principal gate (authenticated, and holding the declared capability) is what
 * keeps roster Users out of other principals' contextual queries. The groups
 * test suite checks capability-less access only through StaffDirectoryReader,
 * whose own canRead() refuses first, so removing either half of the gate
 * leaves the suite green (recorded in docs/audits/packages/groups.ledger.json).
 *
 * This probe is the missing discriminator. On the unmodified policy, a
 * generic contextual User query sees no roster member unless the principal is
 * authenticated and holds the capability. With --mutant=B4 (capability check
 * removed) or --mutant=ANON (authentication check removed), the probe loads a
 * mutated copy of the policy before the autoloader can, and shows the roster
 * leaking to the principal the removed check was keeping out.
 *
 * Synthetic: the real group, user and relationship entity types, policies and
 * contextual query on a throwaway SQLite file from the packages/testing
 * TemporarySqliteDatabase utility. No kernel, no application database.
 *
 * Run from the repository root:
 *
 *   php tests/Fixtures/Audits/Groups/GRP-TEST-001-capability-gate.php
 *   php tests/Fixtures/Audits/Groups/GRP-TEST-001-capability-gate.php --mutant=B4
 *   php tests/Fixtures/Audits/Groups/GRP-TEST-001-capability-gate.php --mutant=ANON
 *
 * To see the groups suite pass with a mutant loaded, write a PHPUnit bootstrap
 * for it into a directory outside the repository, then run the suite with it:
 *
 *   php tests/Fixtures/Audits/Groups/GRP-TEST-001-capability-gate.php --mutant=B4 --emit-bootstrap=<dir>
 *   php vendor/bin/phpunit --no-coverage --do-not-cache-result --bootstrap <dir>/bootstrap.php packages/groups/tests
 *
 * Exit 0 means the code behaves as the audit records: the gate holds without a
 * mutant, and the named mutant leaks the roster. Exit 1 means it doesn't.
 */

use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\ContextualEntityLoader;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Groups\GroupAccessPolicy;
use Waaseyaa\Groups\GroupRelationshipTypes;
use Waaseyaa\Groups\GroupsServiceProvider;
use Waaseyaa\Groups\StaffDirectory\CapabilityScopedStaffDirectoryAccessPolicy;
use Waaseyaa\Groups\StaffDirectory\StaffDirectoryReadDeclaration;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Testing\Database\TemporarySqliteDatabase;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

$mutant = null;
$emit = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('#^--mutant=(B4|ANON)$#', $arg, $m) === 1) {
        $mutant = $m[1];
    } elseif (preg_match('#^--emit-bootstrap=(.+)$#', $arg, $m) === 1) {
        $emit = $m[1];
    } else {
        fwrite(STDERR, "usage: php tests/Fixtures/Audits/Groups/GRP-TEST-001-capability-gate.php [--mutant=B4|--mutant=ANON [--emit-bootstrap=<dir>]]\n");
        exit(2);
    }
}

if ($mutant !== null) {
    // Load the mutated policy before the autoloader can load the real one.
    $source = (string) file_get_contents($root . '/packages/groups/src/StaffDirectory/CapabilityScopedStaffDirectoryReadPolicy.php');
    [$search, $replace] = $mutant === 'B4'
        ? ["\n            || !\$principal->hasPermission(\$this->declaration->capability)\n", "\n"]
        : ["\$operation !== 'view' || !\$principal->isAuthenticated()", "\$operation !== 'view'"];
    if (substr_count($source, $search) !== 1) {
        fwrite(STDERR, "mutant $mutant no longer applies to the policy source\n");
        exit(1);
    }
    $mutated = str_replace($search, $replace, $source);
    if ($emit !== null) {
        $target = rtrim(str_replace('\\', '/', $emit), '/');
        $repository = strtolower(str_replace('\\', '/', $root));
        if (str_starts_with(strtolower($target), $repository)) {
            fwrite(STDERR, "--emit-bootstrap must point outside the repository\n");
            exit(2);
        }
        if (!is_dir($target)) {
            mkdir($target, 0o777, true);
        }
        file_put_contents($target . '/CapabilityScopedStaffDirectoryReadPolicy.php', $mutated);
        file_put_contents($target . '/bootstrap.php', "<?php\n\ndeclare(strict_types=1);\n\n// Mutant $mutant of CapabilityScopedStaffDirectoryReadPolicy (GRP-TEST-001), loaded before the autoloader.\nrequire " . var_export($root . '/tests/bootstrap.php', true) . ";\nrequire __DIR__ . '/CapabilityScopedStaffDirectoryReadPolicy.php';\n");
        echo "wrote $target/bootstrap.php\n";
        exit(0);
    }
    // Load the mutated policy before the autoloader can load the real one.
    $file = tempnam(sys_get_temp_dir(), 'grp_test_001_');
    file_put_contents($file, $mutated);
    require $file;
    unlink($file);
}

$temporary = new TemporarySqliteDatabase();
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
            new SqlStorageDriver($resolver, $definition->getKeys()['id'] ?? 'id'),
            $dispatcher,
            database: $database,
            fieldRegistry: $registry,
        );
    },
    fieldRegistry: $registry,
);
ContentEntityBase::setFieldRegistry($registry);
ContentEntityBase::setEntityTypeManager($manager);
$manager->registerEntityType(EntityType::fromClass(Relationship::class, group: 'content'));
$groupsProvider = new GroupsServiceProvider();
$groupsProvider->register();
foreach ($groupsProvider->getEntityTypes() as $type) {
    if ($type->id() === 'group') {
        $manager->registerEntityType($type);
    }
}
$manager->registerEntityType(EntityType::fromClass(User::class, group: 'people'));

$declaration = new StaffDirectoryReadDeclaration('staff_directory_capability', 'band_member');
$handler = new EntityAccessHandler([
    new UserAccessPolicy(),
    new GroupAccessPolicy(),
    new CapabilityScopedStaffDirectoryAccessPolicy($database, $declaration),
]);
EntityReadRuntime::installGuard(new FieldReadGuard(new AccountFieldReadScope(), $handler->checkProtectedFieldRead(...)));

$groups = $manager->getRepository('group');
$group = $groups->create(['gid' => '1', 'type' => 'band_member', 'name' => 'Roster', 'status' => true]);
$group->enforceIsNew();
$groups->save($group, validate: false);
$users = $manager->getRepository('user');
foreach ([7, 8, 9] as $uid) {
    $user = $users->create(['uid' => $uid, 'name' => 'user-' . $uid, 'mail' => 'user-' . $uid . '@example.test', 'status' => true]);
    $user->enforceIsNew();
    $users->save($user, validate: false);
}
$relationships = $manager->getRepository('relationship');
foreach ([7, 8] as $uid) {
    $edge = $relationships->create([
        'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
        'from_entity_type' => 'user',
        'from_entity_id' => (string) $uid,
        'to_entity_type' => 'group',
        'to_entity_id' => '1',
        'directionality' => 'directed',
        'status' => true,
    ]);
    $relationships->save($edge, validate: false);
}

$roster = static function (AuthorizationPrincipal $principal) use ($manager, $database, $handler, $users): array {
    return new SqlEntityQuery($manager->getDefinition('user'), $database)
        ->withAccessHandler($handler)
        ->withContextualEntityLoader(new ContextualEntityLoader($database, static function (array $ids) use ($users): array {
            $entities = [];
            foreach ($users->findMany($ids) as $entity) {
                if ($entity->id() !== null) {
                    $entities[$entity->id()] = $entity;
                }
            }

            return $entities;
        }))
        ->setAccount($principal)
        ->sort('uid', 'ASC')
        ->execute();
};

$cases = [
    'no permissions' => $roster(new AuthorizationPrincipal(50, true, [], [], 'claims-50')),
    'other permission' => $roster(new AuthorizationPrincipal(51, true, [], ['access content'], 'claims-51')),
    'capability, not authenticated' => $roster(new AuthorizationPrincipal(52, false, [], ['staff_directory_capability'], 'claims-52')),
    'capability holder (control)' => $roster(new AuthorizationPrincipal(100, true, [], ['staff_directory_capability'], 'claims-100')),
];
echo json_encode(['mutant' => $mutant ?? 'none', 'roster_seen' => $cases], JSON_THROW_ON_ERROR), "\n";

$expected = match ($mutant) {
    null => ['no permissions' => [], 'other permission' => [], 'capability, not authenticated' => []],
    'B4' => ['no permissions' => [7, 8], 'other permission' => [7, 8], 'capability, not authenticated' => []],
    'ANON' => ['no permissions' => [], 'other permission' => [], 'capability, not authenticated' => [7, 8]],
};
$ok = $cases['capability holder (control)'] === [7, 8];
foreach ($expected as $case => $ids) {
    $ok = $ok && $cases[$case] === $ids;
}

EntityReadRuntime::installGuard(null);
ContentEntityBase::setEntityTypeManager(null);
ContentEntityBase::setFieldRegistry(null);
exit($ok ? 0 : 1);
