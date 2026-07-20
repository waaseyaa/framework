<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Groups\Group;
use Waaseyaa\Groups\GroupAccessPolicy;
use Waaseyaa\Groups\GroupRelationshipTypes;
use Waaseyaa\Groups\GroupsServiceProvider;
use Waaseyaa\Relationship\AuthorizedRelationshipTraversal;
use Waaseyaa\Relationship\MemberDirectoryEntry;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipAccessPolicy;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

/**
 * @covers \Waaseyaa\Relationship\AuthorizedRelationshipTraversal
 * @covers \Waaseyaa\Relationship\MemberDirectoryEntry
 */
#[CoversClass(AuthorizedRelationshipTraversal::class)]
final class AuthorizedMemberDirectoryTest extends TestCase
{
    private DBALDatabase $database;
    private EntityTypeManager $manager;
    private EntityAccessHandler $accessHandler;
    private AccountFieldReadScope $scope;

    protected function setUp(): void
    {
        EntityType::clearFromClassCache();
        $this->database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $registry = new FieldDefinitionRegistry();
        $resolver = new SingleConnectionResolver($this->database);
        $this->manager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $resolver, $registry): EntityRepository {
                (new SqlSchemaHandler($definition, $this->database, $registry))->ensureTable();

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    new SqlStorageDriver($resolver, $definition->getKeys()['id'] ?? 'id'),
                    $dispatcher,
                    database: $this->database,
                    fieldRegistry: $registry,
                );
            },
            fieldRegistry: $registry,
        );

        ContentEntityBase::setFieldRegistry($registry);
        ContentEntityBase::setEntityTypeManager($this->manager);
        $this->manager->registerEntityType(EntityType::fromClass(Relationship::class, group: 'content'));
        $this->manager->registerEntityType($this->groupEntityType());
        $this->manager->registerEntityType(EntityType::fromClass(User::class, group: 'people'));

        $this->accessHandler = new EntityAccessHandler([
            new GroupAccessPolicy(),
            new RelationshipAccessPolicy(),
            new UserAccessPolicy(),
        ]);
        $this->scope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $this->scope,
            $this->accessHandler->checkProtectedFieldRead(...),
        ));
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
        ContentEntityBase::setEntityTypeManager(null);
        ContentEntityBase::setFieldRegistry(null);
    }

    #[Test]
    public function ordinary_band_member_reads_only_the_opted_in_direct_directory_with_email_sealed(): void
    {
        $this->createGroup('1', true);
        $this->createUser(7, 'Alice', 'alice@example.test');
        $this->createUser(8, 'Bob', 'bob@example.test');
        $this->createMembership(7, '1');
        $this->createMembership(8, '1');
        $principal = $this->bandMember(7);

        $group = $this->manager->getRepository('group')->find('1');
        $otherMember = $this->manager->getRepository('user')->find('8');
        self::assertNotNull($group);
        self::assertNotNull($otherMember);
        self::assertFalse($this->accessHandler->check($group, 'view', $principal)->isAllowed());
        self::assertFalse($this->accessHandler->check($otherMember, 'view', $principal)->isAllowed());

        $entries = $this->service()->memberDirectory($principal, '1');

        self::assertEquals([
            new MemberDirectoryEntry('7', 'Alice'),
            new MemberDirectoryEntry('8', 'Bob'),
        ], $entries);
        self::assertNull($this->scope->current());
        self::assertFalse($this->accessHandler->check($group, 'view', $principal)->isAllowed());
        self::assertFalse($this->accessHandler->check($otherMember, 'view', $principal)->isAllowed());
        $this->expectException(FieldReadDenied::class);
        $otherMember->get('mail');
    }

    #[Test]
    public function broad_directory_is_independent_of_opt_in_and_never_falls_back_to_scoped_entries(): void
    {
        $this->createGroup('1', false);
        $this->createUser(7, 'Alice', 'alice@example.test');
        $this->createUser(8, 'Bob', 'bob@example.test');
        $this->createUser(9, 'Carol', 'carol@example.test');
        $this->createUser(10, 'Dana', 'dana@example.test');
        $this->createUser(11, 'Eve', 'eve@example.test');
        $this->createMembership(7, '1');
        $hiddenEdgeId = $this->createMembership(8, '1');
        $this->createRelationship(
            GroupRelationshipTypes::MEMBERSHIP,
            'user',
            '9',
            'group',
            '1',
            true,
            'bidirectional',
        );
        $this->createMembership(10, '1', startDate: 'malformed');
        $this->createRelationship(
            GroupRelationshipTypes::MEMBERSHIP,
            'node',
            '11',
            'group',
            '1',
            true,
            'directed',
        );
        $this->accessHandler->addPolicy(new class ($hiddenEdgeId) implements AccessPolicyInterface {
            public function __construct(private readonly string $hiddenEdgeId) {}

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'relationship';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $operation === 'view' && (string) $entity->id() === $this->hiddenEdgeId
                    ? AccessResult::forbidden('Directory fixture withholds this edge.')
                    : AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
        });
        $administrator = new AuthorizationPrincipal(
            7,
            true,
            ['administrator'],
            ['administer groups', 'administer nodes', 'administer users'],
            'directory-admin-test',
        );

        self::assertEquals(
            [new MemberDirectoryEntry('7', 'Alice')],
            $this->service()->memberDirectory($administrator, '1'),
        );
    }

    #[Test]
    public function another_group_member_revoked_member_and_non_member_receive_nothing(): void
    {
        $this->createGroup('1', true);
        $this->createGroup('2', true);
        foreach ([[7, 'Alice'], [8, 'Bob'], [9, 'Carol'], [10, 'Dana']] as [$uid, $name]) {
            $this->createUser($uid, $name, strtolower($name) . '@example.test');
        }
        $this->createMembership(7, '1');
        $this->createMembership(8, '1');
        $this->createMembership(9, '2');
        $this->createMembership(10, '2', status: false);

        self::assertSame([], $this->service()->memberDirectory($this->bandMember(7), '2'));
        self::assertSame([], $this->service()->memberDirectory($this->bandMember(10), '2'));
        self::assertSame([], $this->service()->memberDirectory($this->bandMember(999), '2'));
    }

    #[Test]
    public function membership_authority_is_direct_non_transitive_and_never_widens_edges(): void
    {
        $this->createGroup('1', true);
        $this->createGroup('2', true);
        $this->createUser(7, 'Alice', 'alice@example.test');
        $this->createUser(8, 'Bob', 'bob@example.test');
        $this->createMembership(7, '1');
        $this->createMembership(8, '2');
        $this->createRelationship('related_groups', 'group', '1', 'group', '2', true, 'bidirectional');
        $principal = $this->bandMember(7);

        self::assertSame([], $this->service()->memberDirectory($principal, '2'));
        self::assertSame([], $this->service()->edges($principal, 'group', '1', [
            'direction' => 'inbound',
            'relationship_types' => [GroupRelationshipTypes::MEMBERSHIP],
        ]));
    }

    #[Test]
    public function opt_in_and_temporal_bounds_are_strict_and_fail_closed(): void
    {
        foreach ([['1', false], ['2', true]] as [$groupId, $open]) {
            $this->createGroup($groupId, $open);
        }
        foreach ([[7, 'Alice'], [8, 'Boundary'], [9, 'Future'], [10, 'Expired'], [11, 'Malformed'], [12, 'Inactive']] as [$uid, $name]) {
            $this->createUser($uid, $name, strtolower($name) . '@example.test', active: $uid !== 12);
        }
        $this->createMembership(7, '1');
        $this->createMembership(7, '2');
        $this->createMembership(8, '2', startDate: '1000', endDate: '1000');
        $this->createMembership(9, '2', startDate: '1001');
        $this->createMembership(10, '2', endDate: '999');
        $this->createMembership(11, '2', startDate: '1.5');
        $this->createMembership(12, '2');

        self::assertSame([], $this->service()->memberDirectory($this->bandMember(7), '1'));
        self::assertEquals([
            new MemberDirectoryEntry('7', 'Alice'),
            new MemberDirectoryEntry('8', 'Boundary'),
        ], $this->service()->memberDirectory($this->bandMember(7), '2'));
    }

    #[Test]
    public function physically_non_boolean_opt_in_never_authorizes_the_scoped_directory(): void
    {
        $this->createGroup('1', true);
        $this->createUser(7, 'Alice', 'alice@example.test');
        $this->createUser(8, 'Bob', 'bob@example.test');
        $this->createMembership(7, '1');
        $this->createMembership(8, '1');
        self::assertSame(1, $this->database->update('group')
            ->fields(['_data' => '{"status":true,"members_can_view_directory":"1"}'])
            ->condition('gid', '1')
            ->execute());

        self::assertSame([], $this->service()->memberDirectory($this->bandMember(7), '1'));
    }

    #[Test]
    public function scoped_directory_supports_the_historical_dedicated_relationship_columns(): void
    {
        $this->createGroup('1', true);
        $this->createUser(7, 'Alice', 'alice@example.test');
        $this->createUser(8, 'Bob', 'bob@example.test');
        $this->manager->getRepository('relationship');
        $this->database->schema()->dropTable('relationship');
        $this->database->getConnection()->getNativeConnection()->exec(<<<'SQL'
            CREATE TABLE relationship (
              rid INTEGER PRIMARY KEY,
              relationship_type TEXT NOT NULL,
              from_entity_type TEXT NOT NULL,
              from_entity_id TEXT NOT NULL,
              to_entity_type TEXT NOT NULL,
              to_entity_id TEXT NOT NULL,
              directionality TEXT NOT NULL DEFAULT 'directed',
              status INTEGER NOT NULL DEFAULT 1,
              start_date INTEGER DEFAULT NULL,
              end_date INTEGER DEFAULT NULL
            )
            SQL);
        foreach ([[1, '7'], [2, '8']] as [$rid, $uid]) {
            iterator_to_array($this->database->query(
                'INSERT INTO relationship (rid, relationship_type, from_entity_type, from_entity_id, to_entity_type, to_entity_id, directionality, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$rid, GroupRelationshipTypes::MEMBERSHIP, 'user', $uid, 'group', '1', 'directed', 1],
            ));
        }

        self::assertEquals([
            new MemberDirectoryEntry('7', 'Alice'),
            new MemberDirectoryEntry('8', 'Bob'),
        ], $this->service()->memberDirectory($this->bandMember(7), '1'));
    }

    #[Test]
    public function scoped_membership_verification_and_enumeration_use_one_row_set_statement(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/relationship/src/AuthorizedRelationshipTraversal.php');
        self::assertIsString($source);
        $start = strpos($source, 'private function directoryAuthorityRowSet');
        $end = strpos($source, 'private function isActiveMembership', $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($source, $start, $end - $start);

        self::assertStringContainsString("select('group'", $method);
        self::assertStringContainsString("join('relationship'", $method);
        self::assertStringContainsString("fieldExists('relationship', '_data')", $method);
        self::assertStringNotContainsString('getQuery()', $method);
        self::assertStringNotContainsString('findMany(', $method);
        self::assertSame(1, substr_count($method, '->execute()'));
    }

    #[Test]
    public function anonymous_never_receives_a_directory_and_the_public_shape_is_closed(): void
    {
        $this->createGroup('1', true);
        $anonymous = new AuthorizationPrincipal(0, false, [], [], 'anonymous-test');

        self::assertSame([], $this->service()->memberDirectory($anonymous, '1'));

        $method = new \ReflectionMethod(AuthorizedRelationshipTraversal::class, 'memberDirectory');
        self::assertSame(['principal', 'groupId'], array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters(),
        ));
        $properties = (new \ReflectionClass(MemberDirectoryEntry::class))->getProperties(\ReflectionProperty::IS_PUBLIC);
        self::assertSame(['userId', 'displayName'], array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            $properties,
        ));
        foreach ($properties as $property) {
            self::assertTrue($property->isReadOnly());
        }
    }

    private function service(): AuthorizedRelationshipTraversal
    {
        return new AuthorizedRelationshipTraversal(
            $this->manager,
            $this->database,
            $this->accessHandler,
            $this->scope,
            clock: static fn(): int => 1000,
        );
    }

    private function groupEntityType(): EntityTypeInterface
    {
        $provider = new GroupsServiceProvider();
        $provider->register();
        foreach ($provider->getEntityTypes() as $type) {
            if ($type->id() === 'group') {
                return $type;
            }
        }

        self::fail('Groups provider did not register "group" entity type.');
    }

    private function createGroup(string $groupId, bool $membersCanViewDirectory): void
    {
        $repository = $this->manager->getRepository('group');
        $group = $repository->create([
            'gid' => $groupId,
            'type' => 'band',
            'name' => $groupId,
            'status' => true,
            'members_can_view_directory' => $membersCanViewDirectory,
        ]);
        self::assertInstanceOf(Group::class, $group);
        $group->enforceIsNew();
        $repository->save($group, validate: false);
    }

    private function createUser(int $uid, string $name, string $mail, bool $active = true): void
    {
        $repository = $this->manager->getRepository('user');
        $user = $repository->create([
            'uid' => $uid,
            'name' => $name,
            'mail' => $mail,
            'status' => $active,
        ]);
        self::assertInstanceOf(User::class, $user);
        $user->enforceIsNew();
        $repository->save($user, validate: false);
    }

    private function createMembership(
        int $uid,
        string $groupId,
        bool $status = true,
        ?string $startDate = null,
        ?string $endDate = null,
    ): string {
        $values = [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
        return $this->createRelationship(
            GroupRelationshipTypes::MEMBERSHIP,
            'user',
            (string) $uid,
            'group',
            $groupId,
            $status,
            'directed',
            $values,
        );
    }

    /** @param array<string, mixed> $extra */
    private function createRelationship(
        string $relationshipType,
        string $fromType,
        string $fromId,
        string $toType,
        string $toId,
        bool $status,
        string $directionality,
        array $extra = [],
    ): string {
        $repository = $this->manager->getRepository('relationship');
        $relationship = $repository->create($extra + [
            'relationship_type' => $relationshipType,
            'from_entity_type' => $fromType,
            'from_entity_id' => $fromId,
            'to_entity_type' => $toType,
            'to_entity_id' => $toId,
            'directionality' => $directionality,
            'status' => $status,
        ]);
        self::assertInstanceOf(Relationship::class, $relationship);
        $repository->save($relationship, validate: false);

        return (string) $relationship->id();
    }

    private function bandMember(int $uid): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal($uid, true, ['Band Member'], [], 'band-member-test');
    }
}
