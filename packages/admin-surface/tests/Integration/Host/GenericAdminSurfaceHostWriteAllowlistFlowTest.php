<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Integration\Host;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;
use Waaseyaa\Node\NodeServiceProvider;
use Waaseyaa\Node\NodeType;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * CW-v1 option-1 PR-4 rework: {@see GenericAdminSurfaceHost::get()}/`handleUpdate()`
 * fully delegate to {@see \Waaseyaa\Api\JsonApiController}'s `show()`/`update()`,
 * so the echo-tolerant write allowlist ({@see \Waaseyaa\Entity\Write\EntityWritePayloadGuard::evaluateForUpdate()})
 * applies here for free. This is the admin-surface companion to
 * `packages/api/tests/Integration/WriteAllowlistPointerBypassFlowTest.php`'s
 * round-trip pin, exercised through the SAME host surface the admin SPA's
 * `SchemaForm.vue` actually talks to (`GenericAdminSurfaceHost::get()` for the
 * load, `action($type, 'update', ...)` for the save) with a REAL Node +
 * workflow-wired `EntityTypeManager`, not a lightweight fixture entity.
 */
#[CoversNothing]
final class GenericAdminSurfaceHostWriteAllowlistFlowTest extends TestCase
{
    #[Test]
    public function full_attribute_round_trip_through_the_host_persists_the_changed_title_and_the_pointer_stays_self_consistent(): void
    {
        [$entityTypeManager, $db, $transitionService, $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        // 'administer nodes'/'administer content' isolate this test to the
        // write-allowlist guard's own behavior — see the sibling JSON:API
        // test for the full rationale (pre-existing, unrelated field-access
        // gates on uid/type/created/changed/status/workflow_state would
        // otherwise also reject a non-admin's full-attribute echo).
        $admin = $this->account(41, ['administer nodes', 'administer content', 'use editorial transition publish']);
        $accountContext->set($admin);

        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $publishResult = $transitionService->transition($nodeRepository->find($entityId), 'publish', $admin);
        self::assertSame('published', $publishResult->toState);

        $beforeRow = $this->rawNodeRow($db, $entityId);
        self::assertGreaterThan(0, (int) $beforeRow['published_revision_id']);
        self::assertSame($beforeRow['revision_id'], $beforeRow['published_revision_id'], 'sanity: a freshly-published node is self-consistent (tip === published)');

        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $host = new GenericAdminSurfaceHost($entityTypeManager, $accessHandler);
        $request = Request::create('/');
        $request->attributes->set('_account', $admin);
        self::assertNotNull($host->resolveSession($request), 'sanity: the admin account must pass session resolution');

        $scope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $scope,
            static fn(...$args): \Waaseyaa\Access\AccessResult => \Waaseyaa\Access\AccessResult::allowed(
                'The explicit admin edit-surface principal may read Protected node fields.',
            ),
        ));
        $principal = new AuthorizationPrincipal(
            41,
            true,
            ['administrator'],
            ['administer nodes', 'administer content', 'use editorial transition publish'],
            'admin-surface-write-flow-test',
        );

        // The "load for editing" a real SchemaForm.vue performs.
        $getResult = $scope->run($principal, fn() => $host->get('node', $entityId));
        self::assertTrue($getResult->ok, 'sanity: get() must succeed: ' . json_encode($getResult->error));
        $loadedAttributes = $getResult->data['attributes'];
        self::assertArrayNotHasKey(
            'published_revision_id',
            $loadedAttributes,
            'The internal publication pointer must be concealed from the generic edit surface.',
        );

        // SchemaForm.vue's exact shape: `formData.value = { ...entityResult.value.attributes }`,
        // one field edited, then `update(props.entityType, props.entityId, formData.value)`.
        $patchAttributes = $loadedAttributes;
        $patchAttributes['title'] = 'Edited via the admin surface round trip';

        try {
            $updateResult = $scope->run($principal, fn() => $host->action('node', 'update', [
                'id' => $entityId,
                'attributes' => $patchAttributes,
            ]));
        } finally {
            EntityReadRuntime::installGuard(null);
        }

        self::assertTrue($updateResult->ok, 'a full-attribute echo PATCH through the host must not fail: ' . json_encode($updateResult->error));
        self::assertSame('Edited via the admin surface round trip', $updateResult->data['attributes']['title']);

        $afterRow = $this->rawNodeRow($db, $entityId);
        // Post-rebase note (PR-2, #1920, same anchor issue): once a node
        // carries a published pointer, an authorized same-state edit
        // legitimately re-publishes it (same-state republish,
        // `docs/specs/content-workflow.md` "Default-revision discipline"),
        // so the pointer is NOT expected to stay byte-unmoved — the
        // invariant pinned here is that it stays SELF-CONSISTENT with the
        // new tip (a legitimate engine-driven promotion), never a stray
        // value. See the sibling JSON:API round-trip pin test for the full
        // rationale.
        self::assertGreaterThan((int) $beforeRow['revision_id'], (int) $afterRow['revision_id'], 'sanity: the edit must have cut a new revision');
        self::assertSame(
            $afterRow['revision_id'],
            $afterRow['published_revision_id'],
            'the published pointer must stay self-consistent with the new tip through the host — never a stray value',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rawNodeRow(DBALDatabase $db, string $entityId): array
    {
        $rows = iterator_to_array($db->select('node')
            ->fields('node', ['nid', 'revision_id', 'published_revision_id'])
            ->condition('nid', $entityId)
            ->execute());

        self::assertCount(1, $rows);

        return $rows[array_key_first($rows)];
    }

    /**
     * @param list<string> $permissions
     */
    private function account(int $id, array $permissions): AccountInterface
    {
        return new class ($id, $permissions) implements AccountInterface {
            public function __construct(private readonly int $accountId, private readonly array $permissions) {}
            public function id(): int|string
            {
                return $this->accountId;
            }
            public function hasPermission(string $permission): bool
            {
                return \in_array($permission, $this->permissions, true);
            }
            public function getRoles(): array
            {
                return [];
            }
            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    /**
     * Boot pattern copied from
     * {@see \Waaseyaa\Api\Tests\Integration\WriteAllowlistPointerBypassFlowTest::bootWiredProviders()}.
     *
     * @return array{0: EntityTypeManager, 1: DBALDatabase, 2: TransitionService, 3: RequestAccountContext}
     */
    private function bootWiredProviders(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            'node.article' => 'editorial',
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
                new SqlStorageDriver($resolver, $definition->getKeys()['id']),
                $dispatcher,
                $definition->isRevisionable() ? new RevisionableStorageDriver($resolver, $definition) : null,
                $db,
            );
        };

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $repositoryFactory);

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

        $nodeProvider = new NodeServiceProvider();
        $nodeProvider->setKernelServices($kernelServices);
        $nodeProvider->register();

        $workflowProvider = new WorkflowServiceProvider();
        $workflowProvider->setKernelServices($kernelServices);
        $workflowProvider->register();

        foreach ($nodeProvider->getEntityTypes() as $entityType) {
            $entityTypeManager->registerEntityType($entityType);
        }
        foreach ($workflowProvider->getEntityTypes() as $entityType) {
            $entityTypeManager->registerEntityType($entityType);
        }

        $nodeProvider->boot();
        $workflowProvider->boot();

        $entityTypeManager->getRepository('node_type')->save(new NodeType(['type' => 'article', 'name' => 'Article']));

        /** @var TransitionService $transitionService */
        $transitionService = $workflowProvider->resolve(TransitionService::class);

        return [$entityTypeManager, $db, $transitionService, $accountContext];
    }
}
