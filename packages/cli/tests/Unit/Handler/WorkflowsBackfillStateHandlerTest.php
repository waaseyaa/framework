<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\WorkflowsBackfillStateHandler;
use Waaseyaa\CLI\Provider\WorkflowsServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableEntityTrait;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Workflows\DefaultWorkflows;
use Waaseyaa\Workflows\Workflow;

/**
 * CW-v1 WP-2 task 2.7 (#1920): CliTester coverage for
 * `workflows:backfill-state`.
 *
 * The wiring below deliberately builds a bare {@see EntityTypeManager} +
 * {@see EntityRepository} stack with NO {@see \Waaseyaa\Workflows\WorkflowServiceProvider}
 * boot — no `workflows.assignments` config, no `WorkflowStateGuard`/
 * `WorkflowPointerMoveGuard` subscribed to the dispatcher. This models the
 * runbook's mandated order (docs/specs/operations-playbooks.md): the backfill
 * runs BEFORE the binding exists, so the guards are not yet live for the
 * type/bundle under backfill — proving the command needs no binding to run
 * (the "unbound-but-fine" requirement) is therefore the DEFAULT shape of
 * every test here, not a special case.
 */
#[CoversClass(WorkflowsBackfillStateHandler::class)]
final class WorkflowsBackfillStateHandlerTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'wf_backfill_subject';

    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new WorkflowsServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'workflows:backfill-state') {
                return $cmd;
            }
        }

        throw new \RuntimeException('workflows:backfill-state command definition not found');
    }

    private function makeContainer(EntityTypeManagerInterface $manager): ContainerInterface
    {
        return new class ($manager) implements ContainerInterface {
            public function __construct(private readonly EntityTypeManagerInterface $manager) {}

            public function get(string $id): mixed
            {
                if ($id === WorkflowsBackfillStateHandler::class) {
                    return new WorkflowsBackfillStateHandler($this->manager);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === WorkflowsBackfillStateHandler::class;
            }
        };
    }

    /**
     * @return array{0: EntityTypeManager, 1: EntityRepository}
     */
    private function bootEntityTypeManager(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $db): EntityRepositoryInterface {
            $schemaHandler = new SqlSchemaHandler($definition, $db);
            $schemaHandler->ensureTable();
            if ($definition->isRevisionable()) {
                $schemaHandler->ensureRevisionTable();
            }

            $resolver = new SingleConnectionResolver($db);

            return new EntityRepository(
                $definition,
                new SqlStorageDriver($resolver),
                $dispatcher,
                $definition->isRevisionable() ? new RevisionableStorageDriver($resolver, $definition) : null,
                $db,
            );
        };

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $repositoryFactory);

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'workflow',
            label: 'Workflow',
            class: Workflow::class,
            keys: ['id' => 'id', 'label' => 'label'],
            group: 'workflows',
        ));

        $entityTypeManager->registerEntityType(new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'Backfill subject',
            class: BackfillSubject::class,
            keys: $this->entityKeys(),
            revisionable: true,
            revisionDefault: true,
        ));

        $workflowRepository = $entityTypeManager->getRepository('workflow');
        $workflowRepository->save(new Workflow(DefaultWorkflows::EDITORIAL));

        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);
        \assert($repository instanceof EntityRepository);

        return [$entityTypeManager, $repository];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function createLegacyRow(EntityRepository $repository, string $bundle, int $status): array
    {
        $entity = new BackfillSubject(
            ['kind' => $bundle, 'title' => 'Legacy row', 'status' => $status],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $repository->save($entity);

        return [(string) $entity->id(), (int) $entity->get('revision_id')];
    }

    /**
     * The bundle key is deliberately named 'kind', NOT 'bundle' (panel minor
     * 2): a fixture whose bundle column happens to be called 'bundle' cannot
     * distinguish correct `getKeys()['bundle']` resolution from a hardcoded
     * 'bundle' string in the handler's --bundle query condition.
     *
     * @return array<string, string>
     */
    private function entityKeys(): array
    {
        return ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'kind', 'revision' => 'revision_id'];
    }

    /**
     * The adversarial panel's workflow shape (task 2.7 critical fix): a
     * workflow whose live state is `published: true` but `default_revision:
     * false` — a shape `WorkflowValidator` accepts, on which the naive
     * backfill would silently route status=1 rows to initial_state.
     */
    private function saveWorkflowWithoutPublishedDefaultRevisionState(EntityTypeManager $entityTypeManager): void
    {
        $entityTypeManager->getRepository('workflow')->save(new Workflow([
            'id' => 'custom_live',
            'label' => 'Custom live',
            'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft', 'published' => false, 'default_revision' => false],
                'live' => ['label' => 'Live', 'published' => true, 'default_revision' => false],
            ],
            'transitions' => [
                'go_live' => ['label' => 'Go live', 'from' => ['draft'], 'to' => 'live'],
            ],
        ]));
    }

    #[Test]
    public function it_backfills_missing_state_from_status_with_zero_revision_churn(): void
    {
        [$entityTypeManager, $repository] = $this->bootEntityTypeManager();

        [$publishedId, $publishedRevisionBefore] = $this->createLegacyRow($repository, 'article', 1);
        [$draftId, $draftRevisionBefore] = $this->createLegacyRow($repository, 'article', 0);
        $already = new BackfillSubject(
            ['kind' => 'article', 'title' => 'Already stated', 'status' => 1, 'workflow_state' => 'review'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $repository->save($already);
        $alreadyId = (string) $already->id();

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($entityTypeManager));
        $tester->executeMap(['entity_type' => self::ENTITY_TYPE_ID, 'workflow_id' => 'editorial']);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('examined 3', $output);
        self::assertStringContainsString('backfilled 2', $output);
        self::assertStringContainsString('skipped 1', $output);
        self::assertStringContainsString('failed 0', $output);
        // Per-target-state breakdown (panel critical fix): the routing must
        // be visible in the real run's output, not just the total.
        self::assertStringContainsString('backfilled 1 row(s) to "published"', $output);
        self::assertStringContainsString('backfilled 1 row(s) to "draft"', $output);

        $published = $repository->find($publishedId);
        self::assertNotNull($published);
        self::assertSame('published', $published->get('workflow_state'), 'status=1 rows backfill to the published+default_revision state.');
        self::assertSame($publishedRevisionBefore, (int) $published->get('revision_id'), 'Backfill must not create a new revision.');
        self::assertCount(1, $repository->listRevisions($publishedId), 'Exactly the original revision must remain — no churn.');

        $draft = $repository->find($draftId);
        self::assertNotNull($draft);
        self::assertSame('draft', $draft->get('workflow_state'), 'status=0 rows backfill to the workflow initial_state.');
        self::assertSame($draftRevisionBefore, (int) $draft->get('revision_id'));
        self::assertCount(1, $repository->listRevisions($draftId));

        $unchanged = $repository->find($alreadyId);
        self::assertNotNull($unchanged);
        self::assertSame('review', $unchanged->get('workflow_state'), 'Rows with a pre-existing state are left untouched.');
    }

    #[Test]
    public function it_dry_runs_with_zero_writes(): void
    {
        [$entityTypeManager, $repository] = $this->bootEntityTypeManager();

        [$publishedId, $publishedRevisionBefore] = $this->createLegacyRow($repository, 'article', 1);
        [$draftId, $draftRevisionBefore] = $this->createLegacyRow($repository, 'article', 0);

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($entityTypeManager));
        $tester->executeMap(['entity_type' => self::ENTITY_TYPE_ID, 'workflow_id' => 'editorial', '--dry-run' => true]);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('--dry-run:', $output);
        self::assertStringContainsString('2 would be backfilled', $output);
        self::assertStringContainsString('would be set to "published"', $output);
        self::assertStringContainsString('would be set to "draft"', $output);

        // Zero writes, proven directly: workflow_state is still unset and
        // the revision id/history are byte-identical to pre-command state.
        $published = $repository->find($publishedId);
        self::assertNotNull($published);
        self::assertNull($published->get('workflow_state'));
        self::assertSame($publishedRevisionBefore, (int) $published->get('revision_id'));
        self::assertCount(1, $repository->listRevisions($publishedId));

        $draft = $repository->find($draftId);
        self::assertNotNull($draft);
        self::assertNull($draft->get('workflow_state'));
        self::assertSame($draftRevisionBefore, (int) $draft->get('revision_id'));
        self::assertCount(1, $repository->listRevisions($draftId));
    }

    #[Test]
    public function it_is_idempotent_a_second_run_reports_zero_changes(): void
    {
        [$entityTypeManager, $repository] = $this->bootEntityTypeManager();

        $this->createLegacyRow($repository, 'article', 1);
        $this->createLegacyRow($repository, 'article', 0);

        $definition = $this->makeDefinition();
        $container = $this->makeContainer($entityTypeManager);

        $first = CliTester::for($definition, $container);
        $first->executeMap(['entity_type' => self::ENTITY_TYPE_ID, 'workflow_id' => 'editorial']);
        self::assertSame(0, $first->getExitCode());
        self::assertStringContainsString('backfilled 2', $first->getStdout());

        $second = CliTester::for($definition, $container);
        $second->executeMap(['entity_type' => self::ENTITY_TYPE_ID, 'workflow_id' => 'editorial']);
        self::assertSame(0, $second->getExitCode());
        $secondOutput = $second->getStdout();
        self::assertStringContainsString('backfilled 0', $secondOutput);
        self::assertStringContainsString('skipped 2', $secondOutput);
    }

    #[Test]
    public function it_exits_nonzero_on_an_unknown_workflow_id(): void
    {
        [$entityTypeManager] = $this->bootEntityTypeManager();

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($entityTypeManager));
        $tester->executeMap(['entity_type' => self::ENTITY_TYPE_ID, 'workflow_id' => 'does-not-exist']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Unknown workflow "does-not-exist"', $tester->getOutput());
    }

    #[Test]
    public function it_exits_nonzero_on_an_unknown_entity_type(): void
    {
        [$entityTypeManager] = $this->bootEntityTypeManager();

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($entityTypeManager));
        $tester->executeMap(['entity_type' => 'not_a_real_type', 'workflow_id' => 'editorial']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Unknown entity type "not_a_real_type"', $tester->getOutput());
    }

    #[Test]
    public function it_applies_the_bundle_filter(): void
    {
        [$entityTypeManager, $repository] = $this->bootEntityTypeManager();

        [$articleId] = $this->createLegacyRow($repository, 'article', 1);
        [$pageId] = $this->createLegacyRow($repository, 'page', 1);

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($entityTypeManager));
        $tester->executeMap([
            'entity_type' => self::ENTITY_TYPE_ID,
            'workflow_id' => 'editorial',
            '--bundle' => 'article',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('wf_backfill_subject.article', $output);
        self::assertStringContainsString('examined 1', $output);
        self::assertStringContainsString('backfilled 1', $output);

        $article = $repository->find($articleId);
        self::assertNotNull($article);
        self::assertSame('published', $article->get('workflow_state'));

        // The 'page' bundle row is entirely out of scope — the query itself
        // is bundle-filtered, so it is never examined, let alone written.
        $page = $repository->find($pageId);
        self::assertNotNull($page);
        self::assertNull($page->get('workflow_state'));
    }

    #[Test]
    public function it_aborts_when_the_workflow_lacks_a_published_default_revision_state_and_published_rows_need_backfill(): void
    {
        // Panel critical fix: on a workflow whose live state is published:
        // true but default_revision: false (a shape WorkflowValidator
        // accepts), the naive backfill routed EVERY row — status=1 rows
        // included — to initial_state and exited 0 reporting success,
        // silently stamping all published content as 'draft'. The command
        // must instead fail fast: nonzero exit, a clear error naming the
        // workflow shape problem and the remediation, and ZERO writes.
        [$entityTypeManager, $repository] = $this->bootEntityTypeManager();
        $this->saveWorkflowWithoutPublishedDefaultRevisionState($entityTypeManager);

        [$publishedId, $publishedRevisionBefore] = $this->createLegacyRow($repository, 'article', 1);
        [$draftId] = $this->createLegacyRow($repository, 'article', 0);

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($entityTypeManager));
        $tester->executeMap(['entity_type' => self::ENTITY_TYPE_ID, 'workflow_id' => 'custom_live']);

        self::assertSame(1, $tester->getExitCode());
        $output = $tester->getOutput();
        self::assertStringContainsString('custom_live', $output);
        self::assertStringContainsString('no state with published: true AND default_revision: true', $output);

        // Zero writes: BOTH rows (published and unpublished) untouched.
        $published = $repository->find($publishedId);
        self::assertNotNull($published);
        self::assertNull($published->get('workflow_state'));
        self::assertSame($publishedRevisionBefore, (int) $published->get('revision_id'));
        $draft = $repository->find($draftId);
        self::assertNotNull($draft);
        self::assertNull($draft->get('workflow_state'));

        // The abort applies before the dry-run branch too — a dry run
        // against the same shape surfaces the same hard error.
        $dryTester = CliTester::for($this->makeDefinition(), $this->makeContainer($entityTypeManager));
        $dryTester->executeMap(['entity_type' => self::ENTITY_TYPE_ID, 'workflow_id' => 'custom_live', '--dry-run' => true]);
        self::assertSame(1, $dryTester->getExitCode());
        self::assertStringContainsString('no state with published: true AND default_revision: true', $dryTester->getOutput());
    }

    #[Test]
    public function it_proceeds_with_a_notice_when_the_workflow_lacks_a_published_default_revision_state_but_no_published_rows_need_backfill(): void
    {
        // The companion branch of the panel critical fix: with NO status=1
        // rows missing workflow_state, initial_state-only stamping is
        // unambiguous — proceed, but say explicitly that no published-target
        // state exists.
        [$entityTypeManager, $repository] = $this->bootEntityTypeManager();
        $this->saveWorkflowWithoutPublishedDefaultRevisionState($entityTypeManager);

        [$draftId] = $this->createLegacyRow($repository, 'article', 0);
        // A status=1 row that ALREADY carries a state is skipped, so it must
        // not trigger the abort.
        $alreadyLive = new BackfillSubject(
            ['kind' => 'article', 'title' => 'Already live', 'status' => 1, 'workflow_state' => 'live'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $repository->save($alreadyLive);

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($entityTypeManager));
        $tester->executeMap(['entity_type' => self::ENTITY_TYPE_ID, 'workflow_id' => 'custom_live']);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('no published default_revision state', $output);
        self::assertStringContainsString('backfilled 1', $output);
        self::assertStringContainsString('skipped 1', $output);
        self::assertStringContainsString('backfilled 1 row(s) to "draft"', $output);

        $draft = $repository->find($draftId);
        self::assertNotNull($draft);
        self::assertSame('draft', $draft->get('workflow_state'));
        self::assertSame('live', $repository->find((string) $alreadyLive->id())?->get('workflow_state'));
    }

    #[Test]
    public function it_reports_partial_failure_and_exits_nonzero(): void
    {
        // R16 fail-fast lesson: a bulk operator command must surface a
        // partial failure loudly (nonzero exit + per-row detail), never
        // swallow it into an overall "success". Exercised here against a
        // stub repository so one specific row's save() can be forced to
        // throw deterministically, independent of any real storage failure
        // mode.
        $workflow = new Workflow(DefaultWorkflows::EDITORIAL);

        $workflowRepository = $this->createMock(EntityRepositoryInterface::class);
        $workflowRepository->method('find')->with('editorial')->willReturn($workflow);

        $rows = [
            '1' => new PartialFailureStubEntity('1', 'article', 1, null),
            '2' => new PartialFailureStubEntity('2', 'article', 1, null),
            '3' => new PartialFailureStubEntity('3', 'article', 0, null),
        ];

        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('execute')->willReturn(array_keys($rows));

        $subjectRepository = $this->createMock(EntityRepositoryInterface::class);
        $subjectRepository->method('getQuery')->willReturn($query);
        $subjectRepository->method('find')->willReturnCallback(
            static fn(string $id): ?EntityInterface => $rows[$id] ?? null,
        );
        $subjectRepository->method('save')->willReturnCallback(
            static function (EntityInterface $entity) use (&$rows): int {
                if ($entity->id() === '2') {
                    throw new \RuntimeException('simulated write failure');
                }
                $rows[(string) $entity->id()] = $entity;

                return 1;
            },
        );

        $definition = $this->createMock(EntityTypeInterface::class);
        $definition->method('getKeys')->willReturn(['bundle' => 'kind']);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('hasDefinition')->willReturnCallback(
            static fn(string $id): bool => in_array($id, [self::ENTITY_TYPE_ID, 'workflow'], true),
        );
        $entityTypeManager->method('getDefinition')->willReturn($definition);
        $entityTypeManager->method('getRepository')->willReturnCallback(
            static fn(string $id) => $id === 'workflow' ? $workflowRepository : $subjectRepository,
        );

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($entityTypeManager));
        $tester->executeMap(['entity_type' => self::ENTITY_TYPE_ID, 'workflow_id' => 'editorial']);

        self::assertSame(1, $tester->getExitCode());
        $output = $tester->getOutput();
        self::assertStringContainsString('examined 3', $output);
        self::assertStringContainsString('backfilled 2', $output);
        self::assertStringContainsString('failed 1', $output);
        self::assertStringContainsString('id 2: simulated write failure', $output);
    }
}

final class BackfillSubject extends ContentEntityBase implements RevisionableInterface, RevisionableEntityInterface
{
    use RevisionableEntityTrait;

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}

final class PartialFailureStubEntity implements EntityInterface
{
    /** @var array<string, mixed> */
    private array $values;

    public function __construct(string $id, string $bundle, int $status, ?string $workflowState)
    {
        $this->values = [
            'id' => $id,
            'bundle' => $bundle,
            'status' => $status,
            'workflow_state' => $workflowState,
        ];
    }

    public function id(): int|string|null
    {
        return $this->values['id'];
    }

    public function uuid(): string
    {
        return $this->values['id'];
    }

    public function label(): string
    {
        return (string) $this->values['id'];
    }

    public function getEntityTypeId(): string
    {
        return 'wf_backfill_subject';
    }

    public function bundle(): string
    {
        return (string) $this->values['bundle'];
    }

    public function isNew(): bool
    {
        return false;
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, mixed $value): static
    {
        $this->values[$name] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function language(): string
    {
        return 'en';
    }
}
