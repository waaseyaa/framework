<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\GroupsContentAssignHandler;
use Waaseyaa\CLI\Handler\GroupsContentUnassignHandler;
use Waaseyaa\CLI\Handler\GroupsCreateHandler;
use Waaseyaa\CLI\Handler\GroupsMemberAddHandler;
use Waaseyaa\CLI\Handler\GroupsMemberRemoveHandler;
use Waaseyaa\CLI\Provider\GroupsCliServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Groups\GroupsServiceProvider;
use Waaseyaa\Groups\Membership\GroupMembershipService;
use Waaseyaa\Relationship\Relationship;

/**
 * CW-v1 WP-4 (#1920): CliTester coverage for the `groups:*` operator
 * commands (design decision 7).
 *
 * Bootstrap mirrors {@see \Waaseyaa\CLI\Tests\Unit\Handler\WorkflowsBackfillStateHandlerTest}:
 * a bare {@see EntityTypeManager} + {@see EntityRepository} stack, with
 * `group`/`group_type` pulled from the real {@see GroupsServiceProvider}
 * (same pattern as {@see \Waaseyaa\Groups\Tests\Integration\GroupMembershipServiceTest})
 * and `relationship` registered via `TestEntityType::stub()`.
 */
#[CoversClass(GroupsCreateHandler::class)]
#[CoversClass(GroupsMemberAddHandler::class)]
#[CoversClass(GroupsMemberRemoveHandler::class)]
#[CoversClass(GroupsContentAssignHandler::class)]
#[CoversClass(GroupsContentUnassignHandler::class)]
final class GroupsHandlersTest extends TestCase
{
    private EntityTypeManager $manager;

    protected function setUp(): void
    {
        $this->manager = $this->bootEntityTypeManager();
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
    }

    // ----- groups:create -----

    #[Test]
    public function createsAGroupWhenTheGroupTypeAlreadyExists(): void
    {
        $this->createGroupType('department');

        $tester = $this->testerFor('groups:create');
        $tester->execute(['department', 'Finance']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Created group "Finance" (type: department)', $tester->getStdout());
        self::assertStringNotContainsString('Created group_type', $tester->getStdout());
    }

    #[Test]
    public function createsAGroupAndAutoCreatesTheMissingGroupType(): void
    {
        $tester = $this->testerFor('groups:create');
        $tester->execute(['department', 'Finance']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Created group_type "department" (label: Department) — it did not exist yet.', $tester->getStdout());
        self::assertStringContainsString('Created group "Finance" (type: department)', $tester->getStdout());
        self::assertNotNull($this->manager->getRepository('group_type')->find('department'));
    }

    // ----- groups:member-add / groups:member-remove -----

    #[Test]
    public function memberAddSucceedsAgainstAnExistingGroup(): void
    {
        $gid = $this->createGroup('department');
        $tester = $this->testerFor('groups:member-add');

        $tester->execute(['7', $gid]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString(sprintf('Added user 7 as a member of group %s.', $gid), $tester->getStdout());
        self::assertTrue($this->membershipService()->isMemberOfAny(7, [$gid]));
    }

    #[Test]
    public function memberAddFailsWithNonzeroExitForAMissingGroup(): void
    {
        $tester = $this->testerFor('groups:member-add');

        $tester->execute(['7', 'nonexistent-group']);

        self::assertNotSame(0, $tester->getExitCode());
        self::assertStringContainsString('nonexistent-group', $tester->getStderr());
    }

    #[Test]
    public function memberRemoveSucceedsAndSoftRevokes(): void
    {
        $gid = $this->createGroup('department');
        $this->membershipService()->addMember(7, $gid);

        $tester = $this->testerFor('groups:member-remove');
        $tester->execute(['7', $gid]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString(sprintf('Removed user 7 from group %s.', $gid), $tester->getStdout());
        self::assertFalse($this->membershipService()->isMemberOfAny(7, [$gid]));
    }

    // ----- groups:content-assign / groups:content-unassign -----

    #[Test]
    public function contentAssignSucceedsAgainstAnExistingGroup(): void
    {
        $gid = $this->createGroup('department');
        $tester = $this->testerFor('groups:content-assign');

        $tester->execute(['node', '42', $gid]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString(sprintf('Assigned node/42 to group %s.', $gid), $tester->getStdout());
        self::assertSame([$gid], $this->membershipService()->groupIdsForContent('node', 42));
    }

    #[Test]
    public function contentAssignFailsWithNonzeroExitForAMissingGroup(): void
    {
        $tester = $this->testerFor('groups:content-assign');

        $tester->execute(['node', '42', 'nonexistent-group']);

        self::assertNotSame(0, $tester->getExitCode());
        self::assertStringContainsString('nonexistent-group', $tester->getStderr());
    }

    #[Test]
    public function contentUnassignSucceedsAndSoftRevokes(): void
    {
        $gid = $this->createGroup('department');
        $this->membershipService()->assignContent('node', 42, $gid);

        $tester = $this->testerFor('groups:content-unassign');
        $tester->execute(['node', '42', $gid]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString(sprintf('Unassigned node/42 from group %s.', $gid), $tester->getStdout());
        self::assertSame([], $this->membershipService()->groupIdsForContent('node', 42));
    }

    // ----- Wiring -----

    private function testerFor(string $commandName): CliTester
    {
        $provider = new GroupsCliServiceProvider();
        foreach ($provider->consoleCommands() as $command) {
            if ($command->name === $commandName) {
                return CliTester::for($command, $this->container());
            }
        }

        throw new \RuntimeException(sprintf('%s command definition not found', $commandName));
    }

    private function container(): ContainerInterface
    {
        $manager = $this->manager;
        $membershipService = $this->membershipService();

        return new class ($manager, $membershipService) implements ContainerInterface {
            public function __construct(
                private readonly EntityTypeManagerInterface $manager,
                private readonly GroupMembershipService $membershipService,
            ) {}

            public function get(string $id): mixed
            {
                return match ($id) {
                    GroupsCreateHandler::class => new GroupsCreateHandler($this->manager),
                    GroupsMemberAddHandler::class => new GroupsMemberAddHandler($this->membershipService),
                    GroupsMemberRemoveHandler::class => new GroupsMemberRemoveHandler($this->membershipService),
                    GroupsContentAssignHandler::class => new GroupsContentAssignHandler($this->membershipService),
                    GroupsContentUnassignHandler::class => new GroupsContentUnassignHandler($this->membershipService),
                    default => throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id)),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    GroupsCreateHandler::class,
                    GroupsMemberAddHandler::class,
                    GroupsMemberRemoveHandler::class,
                    GroupsContentAssignHandler::class,
                    GroupsContentUnassignHandler::class,
                ], true);
            }
        };
    }

    private function membershipService(): GroupMembershipService
    {
        // Deliberately NOT memoized in a `static` local: that would leak the
        // instance (and its bound EntityTypeManager/database) across test
        // methods within the same PHPUnit process, since PHP `static` locals
        // persist per-process, not per-object. Each test's setUp() builds a
        // fresh in-memory SQLite manager; the service must follow it.
        return new GroupMembershipService($this->manager);
    }

    private function createGroupType(string $id): void
    {
        $repository = $this->manager->getRepository('group_type');
        $entity = $repository->create(['id' => $id, 'label' => ucfirst($id)]);
        $repository->save($entity);
    }

    private function createGroup(string $type): string
    {
        $this->createGroupType($type);
        $repository = $this->manager->getRepository('group');
        $entity = $repository->create(['type' => $type, 'name' => 'Test group']);
        $repository->save($entity);

        return (string) $entity->id();
    }

    private function bootEntityTypeManager(): EntityTypeManager
    {
        EntityType::clearFromClassCache();
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        $dispatcher = new EventDispatcher();
        $registry = new FieldDefinitionRegistry();

        $resolver = new SingleConnectionResolver($database);
        $manager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $resolver, $database, $registry): EntityRepository {
                (new SqlSchemaHandler($definition, $database, $registry))->ensureTable();

                $idKey = $definition->getKeys()['id'] ?? 'id';

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    new SqlStorageDriver($resolver, $idKey),
                    $dispatcher,
                    database: $database,
                    fieldRegistry: $registry,
                );
            },
            fieldRegistry: $registry,
        );

        ContentEntityBase::setFieldRegistry($registry);

        $groupsProvider = new GroupsServiceProvider();
        $groupsProvider->register();
        foreach ($groupsProvider->getEntityTypes() as $type) {
            $manager->registerEntityType($type);
        }

        $manager->registerEntityType(TestEntityType::stub(
            id: 'relationship',
            class: Relationship::class,
            keys: [
                'id' => 'rid',
                'uuid' => 'uuid',
                'label' => 'relationship_type',
                'bundle' => 'relationship_type',
            ],
            label: 'Relationship',
            fieldDefinitions: [
                'relationship_type' => ['type' => 'string', 'required' => true, 'weight' => 0],
                'from_entity_type' => ['type' => 'string', 'required' => true, 'weight' => 1],
                'from_entity_id' => ['type' => 'string', 'required' => true, 'weight' => 2],
                'to_entity_type' => ['type' => 'string', 'required' => true, 'weight' => 3],
                'to_entity_id' => ['type' => 'string', 'required' => true, 'weight' => 4],
                'directionality' => ['type' => 'string', 'weight' => 5, 'default' => 'directed'],
                'status' => ['type' => 'boolean', 'weight' => 6, 'default' => 1],
            ],
        ));

        return $manager;
    }
}
