<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\WorkflowsAuditServingProjectionHandler;
use Waaseyaa\CLI\Provider\WorkflowsServiceProvider as CliWorkflowsServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableEntityTrait;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\DefaultWorkflows;
use Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard;
use Waaseyaa\Workflows\Read\WorkflowEntitySnapshotReader;
use Waaseyaa\Workflows\Workflow;

#[CoversClass(WorkflowsAuditServingProjectionHandler::class)]
final class WorkflowsAuditServingProjectionHandlerTest extends TestCase
{
    #[Test]
    public function it_reports_repairs_and_then_idempotently_clears_a_real_repository_finding(): void
    {
        [$manager, $repository, $database, $container] = $this->boot();
        $entity = $this->row($repository, 'article', 'published', 1);
        $id = (string) $entity->id();
        $revisionId = (int) $entity->get('revision_id');
        $published = $repository->setPublishedRevision($id, $revisionId, $entity->mutationToken());

        // Simulate a legacy write that left the materialized serving status
        // impossible under the still-live published pointer. The historical
        // pointer revision is stale too: repair must derive status from its
        // declared workflow state without rewriting revision history.
        $this->setStoredStatus($database, $id, false);
        $this->setRevisionStoredStatus($database, $id, $revisionId, false);
        self::assertSame(0, (new WorkflowEntitySnapshotReader())->read($repository->loadPublishedRevision($id))->status);

        $definition = $this->definition();
        $report = CliTester::for($definition, $container);
        $report->executeMap([]);
        self::assertSame(0, $report->getExitCode());
        self::assertStringContainsString('1 finding(s)', $report->getStdout());
        $finding = $this->finding($report->getStdout());
        self::assertSame(0, $finding['current_status']);
        self::assertSame(1, $finding['proposed_status']);
        self::assertSame((string) $revisionId, $finding['published_revision']);
        self::assertArrayNotHasKey('title', $finding, 'Reports must not expose protected content.');

        $repair = CliTester::for($definition, $container);
        $repair->executeMap(['--repair' => $id, '--confirm' => $finding['fingerprint']]);
        self::assertSame(0, $repair->getExitCode());
        self::assertStringContainsString('REPAIRED ', $repair->getStdout());
        self::assertSame(1, (new WorkflowEntitySnapshotReader())->read($repository->find($id))->status);
        self::assertSame($revisionId, (int) $repository->find($id)?->get('revision_id'));
        self::assertSame(0, (new WorkflowEntitySnapshotReader())->read($repository->loadPublishedRevision($id))->status, 'Repair must not rewrite historical revision status.');

        $again = CliTester::for($definition, $container);
        $again->executeMap([]);
        self::assertSame(0, $again->getExitCode());
        self::assertStringContainsString('0 finding(s)', $again->getStdout());
    }

    #[Test]
    public function it_does_not_flag_a_forward_draft_or_an_unbound_row(): void
    {
        [, $repository, $database, $container] = $this->boot();
        $live = $this->row($repository, 'article', 'published', 1);
        $id = (string) $live->id();
        $liveRevision = (int) $live->get('revision_id');
        $promoted = $repository->setPublishedRevision($id, $liveRevision, $live->mutationToken());

        $promoted->set('workflow_state', 'draft');
        $promoted->set('title', 'Protected forward draft title');
        $promoted->set('status', 1);
        $promoted->setNewRevision(true);
        $promoted->setDefaultRevisionDiscipline(true);
        $repository->save($promoted, false);
        $reader = new WorkflowEntitySnapshotReader();
        self::assertSame('draft', $reader->read($repository->loadWorkingCopy($id))->workflowState);
        self::assertSame('published', $reader->read($repository->find($id))->workflowState);

        $unbound = $this->row($repository, 'page', 'published', 1);
        $this->setStoredStatus($database, (string) $unbound->id(), false);

        $report = CliTester::for($this->definition(), $container);
        $report->executeMap([]);
        self::assertSame(0, $report->getExitCode());
        self::assertStringContainsString('0 finding(s)', $report->getStdout());
        self::assertStringNotContainsString('Protected forward draft title', $report->getStdout());
    }

    #[Test]
    public function it_requires_an_exact_fingerprint_and_fails_closed_without_a_pointer(): void
    {
        [, $repository, $database, $container] = $this->boot();
        $row = $this->row($repository, 'article', 'draft', 0);
        $id = (string) $row->id();
        $this->setStoredStatus($database, $id, true);

        $report = CliTester::for($this->definition(), $container);
        $report->executeMap([]);
        $finding = $this->finding($report->getStdout());
        self::assertSame(0, $finding['repairable']);

        $repair = CliTester::for($this->definition(), $container);
        $repair->executeMap(['--repair' => $id, '--confirm' => $finding['fingerprint']]);
        self::assertSame(1, $repair->getExitCode());
        self::assertStringContainsString('no published pointer', $repair->getStderr());
        self::assertSame(1, (new WorkflowEntitySnapshotReader())->read($repository->find($id))->status);

        $wrong = CliTester::for($this->definition(), $container);
        $wrong->executeMap(['--repair' => $id, '--confirm' => 'stale-fingerprint']);
        self::assertSame(1, $wrong->getExitCode());
        self::assertStringContainsString('does not match', $wrong->getStderr());
    }

    #[Test]
    public function it_refuses_a_confirmed_finding_after_a_concurrent_aggregate_change(): void
    {
        [, $repository, $database, $container] = $this->boot();
        $row = $this->row($repository, 'article', 'published', 1);
        $id = (string) $row->id();
        $published = $repository->setPublishedRevision($id, (int) $row->get('revision_id'), $row->mutationToken());
        $this->setStoredStatus($database, $id, false);

        $report = CliTester::for($this->definition(), $container);
        $report->executeMap([]);
        $finding = $this->finding($report->getStdout());

        $fresh = $repository->find($id);
        self::assertInstanceOf(ProjectionSubject::class, $fresh);
        $fresh->set('title', 'A concurrent protected edit');
        $repository->save($fresh, false);

        $repair = CliTester::for($this->definition(), $container);
        $repair->executeMap(['--repair' => $id, '--confirm' => $finding['fingerprint']]);
        self::assertSame(1, $repair->getExitCode());
        self::assertStringContainsString('does not match', $repair->getStderr());
    }

    #[Test]
    public function it_refuses_incomplete_confirmation_and_malformed_audit_authority(): void
    {
        [, , , $container] = $this->boot();
        $half = CliTester::for($this->definition(), $container);
        $half->executeMap(['--repair' => '1']);
        self::assertSame(1, $half->getExitCode());
        self::assertStringContainsString('must be supplied together', $half->getStderr());

        $absent = CliTester::for($this->definition(), $container);
        $absent->executeMap(['--repair' => '999', '--confirm' => 'not-a-finding']);
        self::assertSame(1, $absent->getExitCode());
        self::assertStringContainsString('not exactly one current finding', $absent->getStderr());

        [, , , $malformedContainer] = $this->boot(['malformed-binding' => 'editorial']);
        $malformed = CliTester::for($this->definition(), $malformedContainer);
        $malformed->executeMap([]);
        self::assertSame(1, $malformed->getExitCode());
        self::assertStringContainsString('malformed', $malformed->getStderr());

        [, , , $unknownContainer] = $this->boot(['unknown_type.article' => 'editorial']);
        $unknown = CliTester::for($this->definition(), $unknownContainer);
        $unknown->executeMap([]);
        self::assertSame(1, $unknown->getExitCode());
        self::assertStringContainsString('unknown entity type', $unknown->getStderr());
        self::assertStringContainsString('no rows were modified', $unknown->getStderr());
    }

    #[Test]
    public function it_fails_closed_for_a_missing_workflow_or_unknown_authoritative_state(): void
    {
        [, $missingRepository, , $missingContainer] = $this->boot([
            'wf_projection_subject.article' => 'missing_workflow',
        ]);
        $this->row($missingRepository, 'article', 'published', 1);
        $missing = CliTester::for($this->definition(), $missingContainer);
        $missing->executeMap([]);
        self::assertSame(1, $missing->getExitCode());
        self::assertStringContainsString('binding or workflow cannot be resolved', $missing->getStderr());
        self::assertStringContainsString('no rows were modified', $missing->getStderr());

        [, $unknownRepository, , $unknownContainer] = $this->boot();
        $this->row($unknownRepository, 'article', 'legacy_unknown', 0);
        $unknown = CliTester::for($this->definition(), $unknownContainer);
        $unknown->executeMap([]);
        self::assertSame(1, $unknown->getExitCode());
        self::assertStringContainsString('absent or unknown authoritative workflow state', $unknown->getStderr());
        self::assertStringContainsString('no rows were modified', $unknown->getStderr());
    }

    /** @return array{EntityTypeManager, EntityRepository, DBALDatabase, ContainerInterface} */
    private function boot(array $assignments = ['wf_projection_subject.article' => 'editorial']): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $database = DBALDatabase::createSqlite();
        $storage = new MemoryStorage();
        $storage->write('workflows.assignments', $assignments);
        $configFactory = new ConfigFactory($storage, $dispatcher);
        $factory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $database): EntityRepositoryInterface {
            $schema = new SqlSchemaHandler($definition, $database);
            $schema->ensureTable();
            if ($definition->isRevisionable()) {
                $schema->ensureRevisionTable();
            }
            $resolver = new SingleConnectionResolver($database);

            return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $definition,
                new SqlStorageDriver($resolver),
                $dispatcher,
                $definition->isRevisionable() ? new RevisionableStorageDriver($resolver, $definition) : null,
                $database,
            );
        };
        $manager = new EntityTypeManager($dispatcher, null, $factory);
        $manager->registerEntityType(new EntityType(
            id: 'workflow', label: 'Workflow', class: Workflow::class,
            keys: ['id' => 'id', 'label' => 'label'], group: 'workflows',
        ));
        $manager->registerEntityType(new EntityType(
            id: 'wf_projection_subject', label: 'Projection subject', class: ProjectionSubject::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'kind', 'revision' => 'revision_id'],
            revisionable: true, revisionDefault: true,
        ));
        $workflow = new Workflow(DefaultWorkflows::EDITORIAL);
        $workflow->enforceIsNew();
        $manager->getRepository('workflow')->save($workflow);
        $repository = $manager->getRepository('wf_projection_subject');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $bindings = new WorkflowBindingResolver($configFactory, $manager);
        $guard = new WorkflowPointerMoveGuard($bindings, $manager);
        $dispatcher->addListener(BeforeRevisionPointerMoveEvent::class, [$guard, 'onBeforePointerMove']);
        $handler = new WorkflowsAuditServingProjectionHandler($manager, $configFactory, $bindings);
        $container = new class ($handler) implements ContainerInterface {
            public function __construct(private readonly WorkflowsAuditServingProjectionHandler $handler) {}
            public function get(string $id): mixed { return $id === WorkflowsAuditServingProjectionHandler::class ? $this->handler : throw new \RuntimeException($id); }
            public function has(string $id): bool { return $id === WorkflowsAuditServingProjectionHandler::class; }
        };

        return [$manager, $repository, $database, $container];
    }

    private function row(EntityRepository $repository, string $bundle, string $state, int $status): ProjectionSubject
    {
        $row = new ProjectionSubject(
            ['kind' => $bundle, 'title' => 'Protected title', 'workflow_state' => $state, 'status' => $status],
            'wf_projection_subject',
            ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'kind', 'revision' => 'revision_id'],
        );
        $repository->save($row, false);

        return $row;
    }

    private function definition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        foreach ((new CliWorkflowsServiceProvider())->consoleCommands() as $command) {
            if ($command->name === 'workflows:audit-serving-projection') {
                return $command;
            }
        }
        throw new \RuntimeException('command missing');
    }

    private function setStoredStatus(DBALDatabase $database, string $id, bool $status): void
    {
        $rows = \iterator_to_array($database->select('wf_projection_subject', 'record')
            ->addField('record', '_data', 'data')
            ->condition('record.id', $id)
            ->range(0, 1)
            ->execute(), false);
        self::assertCount(1, $rows);
        $data = \json_decode((string) $rows[0]['data'], true, flags: JSON_THROW_ON_ERROR);
        $data['status'] = $status;
        self::assertSame(1, $database->update('wf_projection_subject')
            ->fields(['_data' => \json_encode($data, JSON_THROW_ON_ERROR)])
            ->condition('id', $id)
            ->execute());
    }

    private function setRevisionStoredStatus(DBALDatabase $database, string $id, int $revisionId, bool $status): void
    {
        $rows = \iterator_to_array($database->select('wf_projection_subject_revision', 'record')
            ->addField('record', '_data', 'data')
            ->condition('record.entity_id', $id)
            ->condition('record.revision_id', $revisionId)
            ->range(0, 1)
            ->execute(), false);
        self::assertCount(1, $rows);
        $data = \json_decode((string) $rows[0]['data'], true, flags: JSON_THROW_ON_ERROR);
        $data['status'] = $status;
        self::assertSame(1, $database->update('wf_projection_subject_revision')
            ->fields(['_data' => \json_encode($data, JSON_THROW_ON_ERROR)])
            ->condition('entity_id', $id)
            ->condition('revision_id', $revisionId)
            ->execute());
    }

    /** @return array<string, mixed> */
    private function finding(string $output): array
    {
        self::assertMatchesRegularExpression('/^FINDING (.+)$/m', $output);
        \preg_match('/^FINDING (.+)$/m', $output, $matches);

        return \json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}

final class ProjectionSubject extends ContentEntityBase implements RevisionableInterface, RevisionableEntityInterface
{
    use RevisionableEntityTrait;

    #[Field(type: 'boolean', settings: ['authorizationInput' => true], read: FieldReadLevel::Protected)]
    public bool $status = false;

    #[Field(type: 'string', required: false, settings: ['authorizationInput' => true], read: FieldReadLevel::Protected)]
    public ?string $workflow_state = null;

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
