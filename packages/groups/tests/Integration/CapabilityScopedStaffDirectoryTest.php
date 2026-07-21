<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\ContextualEntityLoader;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlEntityQueryResultCache;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Groups\Group;
use Waaseyaa\Groups\GroupRelationshipTypes;
use Waaseyaa\Groups\GroupsServiceProvider;
use Waaseyaa\Groups\StaffDirectory\CapabilityScopedStaffDirectoryAccessPolicy;
use Waaseyaa\Groups\StaffDirectory\CapabilityScopedStaffDirectoryReadPolicy;
use Waaseyaa\Groups\StaffDirectory\StaffDirectoryReadDeclaration;
use Waaseyaa\Groups\StaffDirectory\StaffDirectoryReader;
use Waaseyaa\Relationship\AuthorizedRelationshipTraversal;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

final class CapabilityScopedStaffDirectoryTest extends TestCase
{
    private DBALDatabase $database;
    private EntityTypeManager $manager;
    private EntityAccessHandler $handler;
    private AccountFieldReadScope $scope;
    private StaffDirectoryReadDeclaration $declaration;
    private string $secondMembershipId;
    private string $databasePath;

    protected function setUp(): void
    {
        EntityType::clearFromClassCache();
        $databasePath = tempnam(sys_get_temp_dir(), 'waaseyaa-staff-directory-');
        if ($databasePath === false) {
            self::fail('Unable to create a temporary SQLite database.');
        }
        $this->databasePath = $databasePath;
        $this->database = DBALDatabase::createSqlite($this->databasePath);
        $dispatcher = new EventDispatcher();
        $registry = new FieldDefinitionRegistry();
        $resolver = new SingleConnectionResolver($this->database);
        $this->manager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $registry, $resolver): EntityRepository {
                new SqlSchemaHandler($definition, $this->database, $registry)->ensureTable();

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

        $this->declaration = new StaffDirectoryReadDeclaration('sfn_manage_members', 'band_member');
        $this->handler = new EntityAccessHandler([
            new UserAccessPolicy(),
            new CapabilityScopedStaffDirectoryAccessPolicy($this->database, $this->declaration),
        ]);
        $this->scope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $this->scope,
            $this->handler->checkProtectedFieldRead(...),
        ));

        $this->createGroup('1', 'band_member');
        $this->createUser(6, true);
        $this->createUser(7, true);
        $this->createUser(8, true);
        $this->createUser(9, true);
        $this->createUser(10, false);
        $this->createMembership(7, '1');
        $this->secondMembershipId = $this->createMembership(8, '1');
        $this->createMembership(10, '1');
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
        ContentEntityBase::setEntityTypeManager(null);
        ContentEntityBase::setFieldRegistry(null);
        $this->database->getConnection()->close();
        if (isset($this->databasePath) && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    #[Test]
    public function clerk_list_count_pagination_and_detail_share_the_exact_live_roster(): void
    {
        $clerk = $this->principal(100, ['sfn_manage_members', 'access user profiles']);
        $reader = $this->reader();

        $firstPage = $reader->list($clerk, 0, 1);
        $secondPage = $reader->list($clerk, 1, 1);

        self::assertSame(2, $firstPage->total);
        self::assertSame([7], array_map(static fn($user): int|string|null => $user->id(), $firstPage->members));
        self::assertSame(2, $secondPage->total);
        self::assertSame([8], array_map(static fn($user): int|string|null => $user->id(), $secondPage->members));
        self::assertSame(8, $reader->detail($clerk, 8)?->id());
        self::assertNull($reader->detail($clerk, 9));
        self::assertNull($reader->detail($clerk, 10));
    }

    #[Test]
    public function ordinary_member_director_profile_viewer_and_anonymous_cannot_enter_the_staff_path(): void
    {
        $reader = $this->reader();
        foreach ([
            $this->principal(7, []),
            $this->principal(50, ['sfn_view_members']),
            $this->principal(51, ['access user profiles']),
            new AuthorizationPrincipal(52, true, ['communications'], ['administer content'], 'communications'),
            new AuthorizationPrincipal(53, true, ['sfn_member_clerk'], [], 'role-label-only'),
            new AuthorizationPrincipal(0, false, [], ['sfn_manage_members'], 'anonymous'),
        ] as $principal) {
            self::assertSame(0, $reader->list($principal)->total);
            self::assertSame([], $reader->list($principal)->members);
            self::assertNull($reader->detail($principal, 7));
        }
    }

    #[Test]
    public function administrator_superuser_semantics_remain_independent_and_explicit(): void
    {
        $administrator = new AuthorizationPrincipal(1, true, ['administrator'], [], 'administrator');

        self::assertSame([7, 8, 10], array_map(
            static fn($user): int|string|null => $user->id(),
            $this->reader()->list($administrator)->members,
        ));
        self::assertSame(8, $this->reader()->detail($administrator, 8)?->id());
    }

    #[Test]
    public function staff_entity_grant_does_not_unseal_protected_or_internal_user_fields(): void
    {
        $clerk = $this->principal(100, ['sfn_manage_members']);
        $member = $this->reader()->detail($clerk, 7);
        self::assertNotNull($member);
        self::assertSame(7, $member->get('uid'));

        foreach (['name', 'status', 'mail', 'roles', 'permissions', 'pass'] as $field) {
            try {
                $this->scope->run($clerk, static fn(): mixed => $member->get($field));
                self::fail(sprintf('The staff entity grant must not release "%s".', $field));
            } catch (FieldReadDenied) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function missing_contextual_policy_remains_no_opinion_and_returns_no_rows(): void
    {
        $reader = new StaffDirectoryReader(
            $this->manager,
            new EntityAccessHandler([new UserAccessPolicy()]),
            $this->declaration,
        );
        $clerk = $this->principal(100, ['sfn_manage_members', 'access user profiles']);

        self::assertSame(0, $reader->list($clerk)->total);
        self::assertSame([], $reader->list($clerk)->members);
        self::assertNull($reader->detail($clerk, 7));
    }

    #[Test]
    public function required_staff_context_limits_dual_authority_without_erasing_generic_profile_access(): void
    {
        $dualAuthority = $this->principal(100, ['sfn_manage_members', 'access user profiles']);
        $profileViewer = $this->principal(101, ['access user profiles']);

        self::assertSame([6, 7, 8, 9], $this->cachedQuery(
            new SqlEntityQueryResultCache(),
            $profileViewer,
        )->execute());
        self::assertSame([7, 8], array_map(
            static fn($user): int|string|null => $user->id(),
            $this->reader()->list($dualAuthority)->members,
        ));
        self::assertNull($this->reader()->detail($dualAuthority, 9));
    }

    #[Test]
    public function peer_and_staff_directory_authorities_do_not_cross_leak(): void
    {
        self::assertSame(1, $this->database->update('group')
            ->fields(['_data' => '{"status":true,"members_can_view_directory":true}'])
            ->condition('gid', '1')
            ->execute());
        $member = $this->principal(7, []);
        $clerk = $this->principal(100, ['sfn_manage_members']);
        $peerReader = new AuthorizedRelationshipTraversal(
            $this->manager,
            $this->database,
            $this->handler,
            $this->scope,
        );

        self::assertCount(2, $peerReader->memberDirectory($member, '1'));
        self::assertSame([], $this->reader()->list($member)->members);
        self::assertSame([], $peerReader->memberDirectory($clerk, '1'));
        self::assertCount(2, $this->reader()->list($clerk)->members);
    }

    #[Test]
    public function contextual_results_are_not_reused_after_membership_revocation(): void
    {
        $clerk = $this->principal(100, ['sfn_manage_members']);
        $cache = new SqlEntityQueryResultCache();

        self::assertSame([7, 8], $this->cachedQuery($cache, $clerk)->execute());
        $relationshipRepository = $this->manager->getRepository('relationship');
        $membership = $relationshipRepository->find($this->secondMembershipId);
        self::assertInstanceOf(Relationship::class, $membership);
        $membership->set('status', false);
        $relationshipRepository->save($membership, validate: false);

        self::assertSame([7], $this->cachedQuery($cache, $clerk)->execute());
        self::assertSame([1], $this->cachedQuery($cache, $clerk)->count()->range(0, 1)->execute());
    }

    #[Test]
    public function identical_cached_count_and_list_observe_group_inactivation(): void
    {
        $clerk = $this->principal(100, ['sfn_manage_members']);
        $cache = new SqlEntityQueryResultCache();

        self::assertSame([2], $this->cachedQuery($cache, $clerk)->count()->range(0, 1)->execute());
        self::assertSame([7, 8], $this->cachedQuery($cache, $clerk)->execute());
        $this->setStoredStatus($this->database, 'group', 'gid', '1', false);

        self::assertSame([0], $this->cachedQuery($cache, $clerk)->count()->range(0, 1)->execute());
        self::assertSame([], $this->cachedQuery($cache, $clerk)->execute());
    }

    #[Test]
    public function only_live_direct_exact_group_memberships_count(): void
    {
        $this->createGroup('2', 'other_roster');
        foreach (range(11, 17) as $uid) {
            $this->createUser($uid, true);
        }
        $this->createMembership(11, '2');
        $this->createMembership(12, '1', [
            'from_entity_type' => 'group',
            'from_entity_id' => '1',
            'to_entity_type' => 'user',
            'to_entity_id' => '12',
        ]);
        $this->createMembership(13, '2');
        $this->createMembership(0, '1', [
            'from_entity_type' => 'group',
            'from_entity_id' => '2',
        ]);
        $this->createMembership(14, '1', ['start_date' => time() + 3600]);
        $this->createMembership(15, '1', ['end_date' => time() - 3600]);
        $this->createMembership(16, '1', ['start_date' => 'not-a-timestamp']);
        $this->createMembership(17, '1', ['status' => false]);
        $clerk = $this->principal(100, ['sfn_manage_members']);

        self::assertSame([7, 8], array_map(
            static fn($user): int|string|null => $user->id(),
            $this->reader()->list($clerk)->members,
        ));
        foreach (range(11, 17) as $uid) {
            self::assertNull($this->reader()->detail($clerk, $uid));
        }
    }

    #[Test]
    public function relationship_storage_failure_returns_zero_page_and_concealed_detail(): void
    {
        iterator_to_array($this->database->query('DROP TABLE relationship'));
        $clerk = $this->principal(100, ['sfn_manage_members']);

        self::assertSame(0, $this->reader()->list($clerk)->total);
        self::assertSame([], $this->reader()->list($clerk)->members);
        self::assertNull($this->reader()->detail($clerk, 7));
    }

    #[Test]
    public function contextual_entity_page_refuses_the_system_query_bypass(): void
    {
        $clerk = $this->principal(100, ['sfn_manage_members']);
        $query = $this->cachedQuery(new SqlEntityQueryResultCache(), $clerk);
        self::assertSame([7, 8], array_map(
            static fn($user): int|string|null => $user->id(),
            $query->executeEntityPage()->entities,
        ));

        $bypassed = $query->accessCheck(false)->executeEntityPage();
        self::assertSame([], $bypassed->entities);
        self::assertSame(0, $bypassed->total);
    }

    #[Test]
    public function contextual_hydration_gap_or_boundary_mismatch_denies_the_complete_batch(): void
    {
        $clerk = $this->principal(100, ['sfn_manage_members']);
        $repository = $this->manager->getRepository('user');
        $query = fn(ContextualEntityLoader $loader): SqlEntityQuery => new SqlEntityQuery(
            $this->manager->getDefinition('user'),
            $this->database,
        )
            ->withAccessHandler($this->handler)
            ->withContextualEntityLoader($loader)
            ->setAccount($clerk)
            ->sort('uid', 'ASC');

        $incomplete = new ContextualEntityLoader(
            $this->database,
            static function (array $ids) use ($repository): array {
                $first = array_slice($ids, 0, 1);
                $entities = [];
                foreach ($repository->findMany($first) as $entity) {
                    if ($entity->id() !== null) {
                        $entities[$entity->id()] = $entity;
                    }
                }

                return $entities;
            },
        );
        self::assertSame([], $query($incomplete)->execute());

        $wrongBoundary = new ContextualEntityLoader(
            new \stdClass(),
            static fn(array $ids): array => [],
        );
        self::assertSame([], $query($wrongBoundary)->execute());
    }

    #[Test]
    #[DataProvider('snapshotMutationProvider')]
    public function candidate_read_establishes_one_snapshot_before_authority_mutations(
        string $mutation,
        string $surface,
        array $nextIds,
    ): void
    {
        $clerk = $this->principal(100, ['sfn_manage_members']);
        $query = $this->queryWithAfterCandidateMutation($clerk, $mutation);
        if ($surface === 'detail') {
            $query = $query->condition('uid', 8)->range(0, 1);
        } elseif ($surface === 'count') {
            $query = $query->count()->range(0, 1);
        }

        if ($surface === 'count') {
            self::assertSame([2], $query->execute());
        } else {
            $page = $query->executeEntityPage();
            self::assertSame($surface === 'detail' ? 1 : 2, $page->total);
            self::assertSame($surface === 'detail' ? [8] : [7, 8], array_map(
                static fn($user): int|string|null => $user->id(),
                $page->entities,
            ));
        }
        self::assertNull($this->reader()->detail($clerk, 8));
        self::assertSame($nextIds, array_map(
            static fn($user): int|string|null => $user->id(),
            $this->reader()->list($clerk)->members,
        ));
        self::assertSame([count($nextIds)], $this->cachedQuery(
            new SqlEntityQueryResultCache(),
            $clerk,
        )->count()->execute());
    }

    /** @return iterable<string, array{string, string, list<int>}> */
    public static function snapshotMutationProvider(): iterable
    {
        foreach (['page', 'count', 'detail'] as $surface) {
            yield 'User status after snapshot, ' . $surface => ['user_status', $surface, [7]];
            yield 'User deletion after snapshot, ' . $surface => ['user_delete', $surface, [7]];
            yield 'membership revoke after snapshot, ' . $surface => ['membership', $surface, [7]];
            yield 'group inactivation after snapshot, ' . $surface => ['group', $surface, []];
        }
    }

    #[Test]
    #[DataProvider('beforeSnapshotMutationProvider')]
    public function mutation_committed_before_snapshot_is_observed_by_page_count_and_detail(
        string $mutation,
        array $expectedIds,
    ): void
    {
        $writer = DBALDatabase::createSqlite($this->databasePath);
        $this->applyMutation($writer, $mutation);
        $writer->getConnection()->close();
        $clerk = $this->principal(100, ['sfn_manage_members']);

        $page = $this->reader()->list($clerk);
        self::assertSame($expectedIds, array_map(
            static fn($user): int|string|null => $user->id(),
            $page->members,
        ));
        self::assertSame(count($expectedIds), $page->total);
        self::assertSame([count($expectedIds)], $this->cachedQuery(
            new SqlEntityQueryResultCache(),
            $clerk,
        )->count()->range(0, 1)->execute());
        self::assertNull($this->reader()->detail($clerk, 8));
    }

    /** @return iterable<string, array{string, list<int>}> */
    public static function beforeSnapshotMutationProvider(): iterable
    {
        yield 'User status before snapshot' => ['user_status', [7]];
        yield 'User deletion before snapshot' => ['user_delete', [7]];
        yield 'membership revoke before snapshot' => ['membership', [7]];
        yield 'group inactivation before snapshot' => ['group', []];
    }

    #[Test]
    public function evaluation_time_is_captured_after_the_lazy_candidate_select_executes(): void
    {
        $membership = $this->manager->getRepository('relationship')->find($this->secondMembershipId);
        self::assertInstanceOf(Relationship::class, $membership);
        $membership->set('start_date', 150);
        $this->manager->getRepository('relationship')->save($membership, validate: false);

        $barrierEntered = false;
        $native = $this->database->getConnection()->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $candidateBarrier = static function (int $uid) use (&$barrierEntered): int {
            $barrierEntered = true;

            return $uid;
        };
        $registered = $native instanceof \Pdo\Sqlite
            ? $native->createFunction('staff_directory_candidate_barrier', $candidateBarrier, 1)
            : $native->sqliteCreateFunction('staff_directory_candidate_barrier', $candidateBarrier, 1);
        self::assertTrue($registered);
        iterator_to_array($this->database->query('ALTER TABLE user RENAME TO user_data'));
        iterator_to_array($this->database->query(
            'CREATE VIEW user AS SELECT staff_directory_candidate_barrier(uid) AS uid, uuid, _data FROM user_data',
        ));

        $query = $this->cachedQuery(
            new SqlEntityQueryResultCache(),
            $this->principal(100, ['sfn_manage_members']),
        );
        $clock = new \ReflectionProperty($query, 'contextualEvaluationClock');
        $clock->setValue($query, static function () use (&$barrierEntered): int {
            return $barrierEntered ? 200 : 100;
        });
        self::assertSame([7, 8], array_map(
            static fn($user): int|string|null => $user->id(),
            $query->executeEntityPage()->entities,
        ));
        self::assertTrue($barrierEntered);
    }

    #[Test]
    public function inactive_or_duplicate_declared_roster_group_fails_closed(): void
    {
        $clerk = $this->principal(100, ['sfn_manage_members']);
        self::assertSame(1, $this->database->update('group')
            ->fields(['_data' => '{"status":false}'])
            ->condition('gid', '1')
            ->execute());
        self::assertSame(0, $this->reader()->list($clerk)->total);
        self::assertNull($this->reader()->detail($clerk, 7));

        self::assertSame(1, $this->database->update('group')
            ->fields(['_data' => '{"status":true}'])
            ->condition('gid', '1')
            ->execute());
        $this->createGroup('2', 'band_member');
        self::assertSame(0, $this->reader()->list($clerk)->total);
        self::assertNull($this->reader()->detail($clerk, 7));
    }

    #[Test]
    public function missing_or_wrong_bundle_roster_group_fails_closed(): void
    {
        $clerk = $this->principal(100, ['sfn_manage_members']);
        self::assertSame(1, $this->database->update('group')
            ->fields(['type' => 'other_roster'])
            ->condition('gid', '1')
            ->execute());
        self::assertSame(0, $this->reader()->list($clerk)->total);
        self::assertNull($this->reader()->detail($clerk, 7));

        self::assertSame(1, $this->database->delete('group')->condition('gid', '1')->execute());
        self::assertSame(0, $this->reader()->list($clerk)->total);
        self::assertNull($this->reader()->detail($clerk, 7));
    }

    private function reader(): StaffDirectoryReader
    {
        return new StaffDirectoryReader($this->manager, $this->handler, $this->declaration);
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
        self::fail('Groups provider did not register the group entity type.');
    }

    private function createGroup(string $id, string $bundle): void
    {
        $repository = $this->manager->getRepository('group');
        $group = $repository->create(['gid' => $id, 'type' => $bundle, 'name' => 'Roster', 'status' => true]);
        self::assertInstanceOf(Group::class, $group);
        $group->enforceIsNew();
        $repository->save($group, validate: false);
    }

    private function createUser(int $uid, bool $active): void
    {
        $repository = $this->manager->getRepository('user');
        $user = $repository->create([
            'uid' => $uid,
            'name' => 'user-' . $uid,
            'mail' => 'user-' . $uid . '@example.test',
            'pass' => 'sealed-password-hash',
            'roles' => ['band_member'],
            'permissions' => ['member-only'],
            'status' => $active,
        ]);
        self::assertInstanceOf(User::class, $user);
        $user->enforceIsNew();
        $repository->save($user, validate: false);
    }

    /** @param array<string, mixed> $overrides */
    private function createMembership(int $uid, string $groupId, array $overrides = []): string
    {
        $repository = $this->manager->getRepository('relationship');
        $relationship = $repository->create($overrides + [
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => (string) $uid,
            'to_entity_type' => 'group',
            'to_entity_id' => $groupId,
            'directionality' => 'directed',
            'status' => true,
        ]);
        self::assertInstanceOf(Relationship::class, $relationship);
        $repository->save($relationship, validate: false);

        return (string) $relationship->id();
    }

    private function cachedQuery(SqlEntityQueryResultCache $cache, AuthorizationPrincipal $principal): SqlEntityQuery
    {
        $repository = $this->manager->getRepository('user');

        return new SqlEntityQuery($this->manager->getDefinition('user'), $this->database, $cache)
            ->withAccessHandler($this->handler)
            ->withContextualEntityLoader(new ContextualEntityLoader($this->database, static function (array $ids) use ($repository): array {
                $entities = [];
                foreach ($repository->findMany($ids) as $entity) {
                    if ($entity->id() !== null) {
                        $entities[$entity->id()] = $entity;
                    }
                }

                return $entities;
            }))
            ->setAccount($principal)
            ->sort('uid', 'ASC');
    }

    private function queryWithAfterCandidateMutation(AuthorizationPrincipal $principal, string $mutation): SqlEntityQuery
    {
        $writer = DBALDatabase::createSqlite($this->databasePath);
        $repository = $this->manager->getRepository('user');
        $mutated = false;
        $loader = new ContextualEntityLoader(
            $this->database,
            function (array $ids) use ($writer, $repository, $mutation, &$mutated): array {
                if (!$mutated) {
                    $this->applyMutation($writer, $mutation);
                    $writer->getConnection()->close();
                    $mutated = true;
                }
                $entities = [];
                foreach ($repository->findMany($ids) as $entity) {
                    if ($entity->id() !== null) {
                        $entities[$entity->id()] = $entity;
                    }
                }

                return $entities;
            },
        );

        return new SqlEntityQuery($this->manager->getDefinition('user'), $this->database)
            ->withAccessHandler($this->handler)
            ->withContextualEntityLoader($loader)
            ->requireContextualPolicy(CapabilityScopedStaffDirectoryReadPolicy::contextKeyFor($this->declaration))
            ->setAccount($principal)
            ->sort('uid', 'ASC');
    }

    private function applyMutation(DBALDatabase $writer, string $mutation): void
    {
        match ($mutation) {
            'user_status' => $this->setStoredStatus($writer, 'user', 'uid', 8, false),
            'user_delete' => self::assertSame(1, $writer->delete('user')->condition('uid', 8)->execute()),
            'membership' => $this->setStoredStatus(
                $writer,
                'relationship',
                'rid',
                $this->secondMembershipId,
                false,
            ),
            'group' => $this->setStoredStatus($writer, 'group', 'gid', '1', false),
            default => throw new \LogicException('Unknown snapshot mutation.'),
        };
    }

    private function setStoredStatus(
        DBALDatabase $database,
        string $table,
        string $idField,
        int|string $id,
        bool $status,
    ): void
    {
        $rows = iterator_to_array($database->select($table, 'record')
            ->addField('record', '_data', 'data')
            ->condition('record.' . $idField, $id)
            ->range(0, 1)
            ->execute(), false);
        self::assertCount(1, $rows);
        $data = json_decode((string) $rows[0]['data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $data['status'] = $status;
        self::assertSame(1, $database->update($table)
            ->fields(['_data' => json_encode($data, JSON_THROW_ON_ERROR)])
            ->condition($idField, $id)
            ->execute());
    }

    /** @param list<string> $permissions */
    private function principal(int $id, array $permissions): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal($id, true, [], $permissions, 'claims-' . $id);
    }
}
