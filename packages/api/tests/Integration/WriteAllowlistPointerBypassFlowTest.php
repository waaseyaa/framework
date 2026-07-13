<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Database\DBALDatabase;
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
 * CW-v1 option-1 design §5 (PR-4), the pinned end-to-end reproduction of
 * `.superpowers/sdd/final-review-findings.md` finding #1 [CRITICAL]: "Raw
 * JSON:API PATCH can move the published pointer by writing
 * published_revision_id directly, bypassing WorkflowPointerMoveGuard and
 * every transition permission" — and finding #2 [IMPORTANT], its
 * generalization to `revision_id` on create and update.
 *
 * Real SQLite storage, the REAL {@see NodeServiceProvider} and
 * {@see WorkflowServiceProvider} wiring (both `NodeRevisionDefaultListener`
 * and `WorkflowStateGuard`/`WorkflowPointerMoveGuard` live on the same
 * dispatcher the repository saves through), the REAL {@see NodeAccessPolicy}
 * (not a fixture), and a real {@see JsonApiController} — the exact stack
 * finding #1's scenario walks through. Before this PR: an account holding
 * only `edit any article content` (no workflow/publish permission) could move
 * the published pointer through a PATCH body because
 * `JsonApiController::update()` applied every submitted attribute with only
 * per-field ACCESS as the gate, and no field policy covers
 * `published_revision_id`/`revision_id` (neither has a field definition).
 * After this PR: {@see \Waaseyaa\Entity\Write\EntityWritePayloadGuard} rejects
 * the attribute structurally, before `set()`/`save()` ever runs — 422, and
 * the base row is proven byte-unmoved via a raw SQL read (not the
 * repository's own pointer-aware accessor, so the assertion cannot be
 * satisfied by anything the write path itself might get wrong).
 */
#[CoversNothing]
final class WriteAllowlistPointerBypassFlowTest extends TestCase
{
    #[Test]
    public function eve_cannot_move_the_published_pointer_through_a_patch_body(): void
    {
        [$entityTypeManager, $db, $transitionService, $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $editor = $this->account(11, [
            'create article content',
            'use editorial transition publish',
            'use editorial transition archive',
        ]);
        $accountContext->set($editor);

        // Build the finding-#1 scenario: publish, then archive, so an older
        // 'published'-stamped revision (rev_P) exists in history while the
        // CURRENT published pointer targets the archived revision (rev_A).
        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();

        $created = $nodeRepository->find($entityId);
        \assert($created !== null);
        $publishResult = $transitionService->transition($created, 'publish', $editor);
        self::assertSame('published', $publishResult->toState);
        $revP = (int) $nodeRepository->find($entityId)?->get('revision_id');

        $archived = $nodeRepository->find($entityId);
        \assert($archived !== null);
        $archiveResult = $transitionService->transition($archived, 'archive', $editor);
        self::assertSame('archived', $archiveResult->toState);

        self::assertSame('archived', $nodeRepository->find($entityId)?->get('workflow_state'));

        $beforeRow = $this->rawNodeRow($db, $entityId);
        self::assertNotSame($revP, (int) $beforeRow['published_revision_id'], 'sanity: archive must have moved the pointer past rev_P');
        $revA = (int) $beforeRow['published_revision_id'];

        // Eve: entity update access, ZERO workflow/publish permission.
        $eve = $this->account(12, ['edit any article content']);
        $accountContext->set($eve);
        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $controller = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
            $accessHandler,
            $eve,
        );

        // Entity-level update access alone would have been enough under the
        // pre-guard code: field access on published_revision_id is neutral
        // (no shipped policy names it) -> open-by-default -> not Forbidden.
        $doc = $controller->update('node', $entityId, [
            'data' => [
                'type' => 'node',
                'attributes' => ['published_revision_id' => $revP],
            ],
        ]);
        $array = $doc->toArray();

        self::assertSame(422, $doc->statusCode);
        self::assertSame('FIELD_NOT_WRITABLE', $array['errors'][0]['code']);
        self::assertSame(['published_revision_id'], $array['errors'][0]['meta']['refused_keys']);

        // The base row's pointer is PROVEN unmoved — a raw SQL read, not the
        // pointer-aware repository accessor, so the assertion is independent
        // of anything the write path itself might get wrong.
        $afterRow = $this->rawNodeRow($db, $entityId);
        self::assertSame($beforeRow, $afterRow, 'a refused PATCH must leave the base row byte-identical');
        self::assertSame($revA, (int) $afterRow['published_revision_id'], 'the published pointer must still target the archived revision, not rev_P');
    }

    #[Test]
    public function eve_cannot_forge_revision_id_on_update(): void
    {
        [$entityTypeManager, $db, $transitionService, $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $editor = $this->account(11, ['create article content', 'use editorial transition publish']);
        $accountContext->set($editor);

        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $transitionService->transition($nodeRepository->find($entityId), 'publish', $editor);

        $beforeRow = $this->rawNodeRow($db, $entityId);

        $eve = $this->account(12, ['edit any article content']);
        $accountContext->set($eve);
        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $controller = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
            $accessHandler,
            $eve,
        );

        $doc = $controller->update('node', $entityId, [
            'data' => [
                'type' => 'node',
                'attributes' => ['revision_id' => 999999],
            ],
        ]);
        $array = $doc->toArray();

        self::assertSame(422, $doc->statusCode);
        self::assertSame(['revision_id'], $array['errors'][0]['meta']['refused_keys']);

        $afterRow = $this->rawNodeRow($db, $entityId);
        self::assertSame($beforeRow, $afterRow, 'a refused PATCH must leave the base row byte-identical');
    }

    #[Test]
    public function eve_cannot_forge_revision_id_on_create(): void
    {
        [$entityTypeManager, $db, , $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $eve = $this->account(12, ['create article content']);
        $accountContext->set($eve);
        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $controller = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
            $accessHandler,
            $eve,
        );

        $doc = $controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => ['title' => 'Forged', 'type' => 'article', 'slug' => 'forged', 'revision_id' => 999999],
            ],
        ]);
        $array = $doc->toArray();

        self::assertSame(422, $doc->statusCode);
        self::assertSame(['revision_id'], $array['errors'][0]['meta']['refused_keys']);
        self::assertSame([], $nodeRepository->findBy([]), 'a refused create must persist nothing');
    }

    #[Test]
    public function wp0_publish_gate_on_status_is_unchanged_by_the_write_allowlist(): void
    {
        // Design §5 "do not double-gate": status/workflow_state pass the new
        // structural guard (they are declared fields) — their write stays
        // governed by field-level access exactly as before (NodeAccessPolicy
        // WP-0 gate). Gated account -> 403 field access; permitted account
        // -> applied (200), never a write-allowlist refusal for either.
        [$entityTypeManager, , , $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $author = $this->account(11, ['create article content', 'use editorial transition publish']);
        $accountContext->set($author);
        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();

        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);

        // Gated: edit access but no publish permission.
        $gated = $this->account(13, ['edit any article content']);
        $accountContext->set($gated);
        $gatedController = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
            $accessHandler,
            $gated,
        );
        $gatedDoc = $gatedController->update('node', $entityId, [
            'data' => ['type' => 'node', 'attributes' => ['status' => 0]],
        ]);
        self::assertSame(403, $gatedDoc->statusCode);
        self::assertStringContainsString('status', $gatedDoc->toArray()['errors'][0]['detail']);

        // Permitted: edit access AND the publish permission.
        $permitted = $this->account(14, ['edit any article content', 'use editorial transition publish']);
        $accountContext->set($permitted);
        $permittedController = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
            $accessHandler,
            $permitted,
        );
        $permittedDoc = $permittedController->update('node', $entityId, [
            'data' => ['type' => 'node', 'attributes' => ['status' => 0]],
        ]);
        self::assertSame(200, $permittedDoc->statusCode);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawNodeRow(DBALDatabase $db, string $entityId): array
    {
        // `status`/`workflow_state` are stored in the `_data` JSON blob, not
        // real base columns (only revision_id/published_revision_id are
        // promoted — packages/node/migrations/2026_07_06_000001_node_revision_schema.php);
        // the raw read is scoped to the real pointer columns, the exact
        // ones finding #1/#2 name.
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
            public function id(): int|string { return $this->accountId; }
            public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return true; }
        };
    }

    /**
     * Boot pattern copied from
     * {@see \Waaseyaa\Workflows\Tests\Integration\ForwardDraftFlowTest::bootWiredProviders()},
     * simplified: no test-local workflow needed — the shipped `editorial`
     * workflow's `publish`/`archive` transitions are all this scenario uses
     * (the `revise` forward-draft edge is not exercised).
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

            return new EntityRepository(
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
