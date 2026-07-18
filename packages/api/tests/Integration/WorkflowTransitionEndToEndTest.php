<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\WorkflowTransitionController;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\Storage\MemoryStorage;
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
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * End-to-end integration: real SQLite storage, the REAL
 * `WorkflowServiceProvider` (not a mock/fake), and `WorkflowTransitionController`
 * driving GET then POST through it — proving the ambient-account-sync caveat
 * (docs/specs/content-workflow.md "Caveat: ambient vs. explicit account",
 * design decision 4 of the WP-4 plan): the controller passes `_account`
 * explicitly to `TransitionService`, but `WorkflowStateGuard`/
 * `WorkflowPointerMoveGuard` re-gate the service's OWN internal saves
 * against the ambient `AccountContextInterface` — normally synced by
 * `SessionMiddleware` for every HTTP request. There is no middleware in this
 * test, so it syncs the ambient context itself, mirroring production.
 *
 * Boot pattern copied from
 * {@see \Waaseyaa\Workflows\Tests\Integration\GuardWiringTest::bootWiredProvider()}.
 */
#[CoversNothing]
final class WorkflowTransitionEndToEndTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'wf_e2e_subject';

    #[Test]
    public function getThenPostDrivesARealTransitionAndPersistsIt(): void
    {
        [$entityTypeManager, $transitionService, $accountContext] = $this->bootWiredProvider();

        $author = $this->account(1, ['use editorial transition submit_for_review']);
        $accessHandler = new EntityAccessHandler([$this->allowAllPolicy()]);
        $controller = new WorkflowTransitionController($entityTypeManager, $accessHandler, $transitionService);

        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);

        // Author acts: create the subject (guard forces initial 'draft').
        $accountContext->set($author);
        $entity = new WorkflowE2ESubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'title' => 'Subject'],
            self::ENTITY_TYPE_ID,
            ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
        );
        $repository->save($entity);
        $id = (string) $entity->id();

        $stored = $repository->find($id);
        $this->assertNotNull($stored);
        $this->assertSame('draft', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($stored));

        // GET available transitions.
        $getRequest = Request::create("/api/wf_e2e_subject/{$id}/workflow/transitions", 'GET');
        $getRequest->attributes->set('_account', $author);

        $getResponse = $controller->transitions($getRequest, self::ENTITY_TYPE_ID, $id);
        $this->assertSame(200, $getResponse->getStatusCode());
        $getBody = json_decode((string) $getResponse->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $available = array_column($getBody['data'], 'id');
        $this->assertContains('submit_for_review', $available);
        $this->assertSame('draft', $getBody['meta']['workflow_state']);

        // POST the transition. The ambient context is already synced to
        // $author above — mirroring what SessionMiddleware does per-request.
        $postRequest = Request::create(
            "/api/wf_e2e_subject/{$id}/workflow/transition",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"transition":"submit_for_review"}',
        );
        $postRequest->attributes->set('_account', $author);

        $postResponse = $controller->transition($postRequest, self::ENTITY_TYPE_ID, $id);
        $this->assertSame(200, $postResponse->getStatusCode());
        $postBody = json_decode((string) $postResponse->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('review', $postBody['data']['to']);

        // Persisted state actually changed.
        $reloaded = $repository->find($id);
        $this->assertNotNull($reloaded);
        $this->assertSame('review', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($reloaded));
    }

    #[Test]
    public function permissionDeniedPostLeavesPersistedStateUnchanged(): void
    {
        [$entityTypeManager, $transitionService, $accountContext] = $this->bootWiredProvider();

        $author = $this->account(1, ['use editorial transition submit_for_review']);
        $outsider = $this->account(2, []);
        $accessHandler = new EntityAccessHandler([$this->allowAllPolicy()]);
        $controller = new WorkflowTransitionController($entityTypeManager, $accessHandler, $transitionService);

        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);

        $accountContext->set($author);
        $entity = new WorkflowE2ESubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'title' => 'Subject'],
            self::ENTITY_TYPE_ID,
            ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
        );
        $repository->save($entity);
        $id = (string) $entity->id();

        // Outsider acts (no permission). Sync the ambient context to match
        // the explicit _account, exactly as SessionMiddleware would.
        $accountContext->set($outsider);
        $postRequest = Request::create(
            "/api/wf_e2e_subject/{$id}/workflow/transition",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"transition":"submit_for_review"}',
        );
        $postRequest->attributes->set('_account', $outsider);

        $response = $controller->transition($postRequest, self::ENTITY_TYPE_ID, $id);

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('permission', $body['errors'][0]['meta']['reason']);

        $reloaded = $repository->find($id);
        $this->assertNotNull($reloaded);
        $this->assertSame('draft', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($reloaded), 'A denied transition must never mutate persisted state.');
    }

    private function allowAllPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool { return true; }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }
        };
    }

    private function account(int $id, array $permissions): AccountInterface
    {
        return new class ($id, $permissions) implements AccountInterface {
            public function __construct(private readonly int $accountId, private readonly array $permissions) {}
            public function id(): int|string { return $this->accountId; }
            public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return $this->accountId !== 0; }
        };
    }

    /**
     * @return array{0: EntityTypeManager, 1: TransitionService, 2: RequestAccountContext}
     */
    private function bootWiredProvider(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            self::ENTITY_TYPE_ID . '.' . self::ENTITY_TYPE_ID => 'editorial',
        ]);
        $configFactory = new ConfigFactory($configStorage, $dispatcher);

        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $db): EntityRepositoryInterface {
            $schemaHandler = new SqlSchemaHandler($definition, $db);
            $schemaHandler->ensureTable();
            if ($definition->isRevisionable()) {
                $schemaHandler->ensureRevisionTable();
            }

            $resolver = new SingleConnectionResolver($db);

            return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
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
            label: 'Workflow API E2E subject',
            class: WorkflowE2ESubject::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
        ));

        $accountContext = new RequestAccountContext();

        $kernelServices = new class ($dispatcher, $entityTypeManager, $configFactory, $accountContext) implements KernelServicesInterface {
            public function __construct(
                private readonly SymfonyEventDispatcherAdapter $dispatcher,
                private readonly EntityTypeManager $entityTypeManager,
                private readonly ConfigFactoryInterface $configFactory,
                private readonly AccountContextInterface $accountContext,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    EntityTypeManager::class, EntityTypeManagerInterface::class => $this->entityTypeManager,
                    ConfigFactoryInterface::class => $this->configFactory,
                    AccountContextInterface::class => $this->accountContext,
                    default => null,
                };
            }
        };

        $provider = new WorkflowServiceProvider();
        $provider->setKernelServices($kernelServices);
        $provider->register();
        $provider->boot();

        /** @var TransitionService $transitionService */
        $transitionService = $provider->resolve(TransitionService::class);

        return [$entityTypeManager, $transitionService, $accountContext];
    }
}

final class WorkflowE2ESubject extends ContentEntityBase implements RevisionableInterface, RevisionableEntityInterface
{
    use RevisionableEntityTrait;
    use \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectFields;

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
