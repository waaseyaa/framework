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
use Waaseyaa\Access\Policy\PublishedContentAccessPolicy;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiResource;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Support\AccountScopedJsonApiController;
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
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\Workflows\DefaultWorkflows;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * CW-v1 option-1 (#1920 PR-3, design §4): the centerpiece HTTP-level oracle
 * the design's PR sequence names explicitly (§11: "Working-copy round trip:
 * edit surface loads tip, PATCH lands on tip, anonymous GET still serves
 * published; transition endpoints report tip state") — real SQLite storage,
 * the REAL {@see NodeServiceProvider}/{@see WorkflowServiceProvider} wiring,
 * the REAL {@see NodeAccessPolicy}/{@see PublishedContentAccessPolicy}, and a
 * real {@see JsonApiController}, exactly the stack `WriteAllowlistPointerBypassFlowTest`
 * (#1920 PR-4) already exercises for the write-allowlist. Boot pattern
 * mirrors {@see \Waaseyaa\Workflows\Tests\Integration\ForwardDraftFlowTest}
 * (test-local `editorial_forward` workflow carrying the `revise` edge the
 * shipped `editorial` workflow does not yet ship — PR-5 territory).
 */
#[CoversNothing]
final class WorkingCopyPointerAwarenessFlowTest extends TestCase
{
    #[Test]
    public function anonymous_get_stays_byte_stable_through_a_forward_draft_window_while_the_editor_sees_and_patches_the_tip(): void
    {
        [$entityTypeManager, $db, $transitionService, $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $editor = $this->account(11, [
            'administer nodes',
            'use editorial_forward transition publish',
            'use editorial_forward transition revise',
        ]);
        $accountContext->set($editor);

        $accessHandler = new EntityAccessHandler([
            new NodeAccessPolicy(),
            new PublishedContentAccessPolicy($entityTypeManager),
        ]);
        $anon = new AnonymousUser();
        $anonController = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $anon),
            $accessHandler,
            $anon,
        );
        $editorController = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $editor),
            $accessHandler,
            $editor,
        );

        // --- Publish a node. ---
        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $publishResult = $transitionService->transition($nodeRepository->find($entityId), 'publish', $editor);
        self::assertSame('published', $publishResult->toState);

        $publishedRevisionId = (int) $nodeRepository->find($entityId)?->get('revision_id');

        // --- Capture the anonymous GET body BEFORE any draft exists. ---
        $anonGetDoc1 = $anonController->show('node', $entityId);
        self::assertSame([], $anonGetDoc1->errors);
        $anonBody1 = json_encode($anonGetDoc1->toArray(), JSON_THROW_ON_ERROR);

        // --- Forward-draft edit (published -> draft) via a validated ---
        // TransitionService save, not a raw repository write.
        $tip = $nodeRepository->find($entityId);
        self::assertNotNull($tip);
        \assert($tip instanceof Node);
        $tip->setTitle('Forward draft title');
        $reviseResult = $transitionService->transition($tip, 'revise', $editor);
        self::assertSame('draft', $reviseResult->toState);

        // --- Anonymous GET must be BYTE-IDENTICAL to the pre-draft capture. ---
        $anonGetDoc2 = $anonController->show('node', $entityId);
        $anonBody2 = json_encode($anonGetDoc2->toArray(), JSON_THROW_ON_ERROR);
        self::assertSame($anonBody1, $anonBody2, 'Anonymous GET must stay byte-stable through the entire draft window.');

        // --- ?workingCopy=1 as the editor serves the draft title. ---
        $workingCopyDoc = $editorController->show('node', $entityId, ['workingCopy' => '1']);
        self::assertSame([], $workingCopyDoc->errors);
        \assert($workingCopyDoc->data instanceof JsonApiResource);
        self::assertSame('Forward draft title', $workingCopyDoc->data->attributes['title']);
        self::assertSame('draft', $workingCopyDoc->data->attributes['workflow_state']);

        // --- PATCH as the editor lands on the draft TIP, not the published ---
        // row: loadRevision() of the tip proves the new content landed there,
        // and a raw SQL read of the published row proves it did NOT move.
        $beforePatchRow = $this->rawNodeRow($db, $entityId);
        $patchDoc = $editorController->update('node', $entityId, [
            'data' => ['type' => 'node', 'attributes' => ['title' => 'Patched draft title']],
        ]);
        self::assertSame(200, $patchDoc->statusCode, 'PATCH as the editor must succeed: ' . json_encode($patchDoc->toArray()));

        $tipAfterPatch = $nodeRepository->loadWorkingCopy($entityId);
        self::assertNotNull($tipAfterPatch);
        self::assertSame('Patched draft title', $tipAfterPatch->get('title'));
        $tipRevisionId = (int) $tipAfterPatch->get('revision_id');
        $reloadedViaLoadRevision = $nodeRepository->loadRevision($entityId, $tipRevisionId);
        self::assertNotNull($reloadedViaLoadRevision);
        self::assertSame('Patched draft title', $reloadedViaLoadRevision->get('title'), 'loadRevision() of the tip must carry the PATCHed content.');

        // Raw SQL read of the base row — not the repository's own
        // pointer-aware accessor — so this assertion cannot be satisfied by
        // anything the write path itself might get wrong.
        $afterPatchRow = $this->rawNodeRow($db, $entityId);
        self::assertSame($beforePatchRow, $afterPatchRow, 'The published (base) row must be byte-unchanged by a PATCH that targets the working copy.');
        self::assertSame($publishedRevisionId, (int) $afterPatchRow['revision_id']);

        // Anonymous GET must STILL be byte-identical (the PATCH landed on the
        // tip, never on what anonymous reads).
        $anonGetDoc3 = $anonController->show('node', $entityId);
        $anonBody3 = json_encode($anonGetDoc3->toArray(), JSON_THROW_ON_ERROR);
        self::assertSame($anonBody1, $anonBody3, 'Anonymous GET must stay byte-stable after a PATCH that targets the working copy.');

        // --- Promote via TransitionService: anonymous GET now serves the ---
        // new content.
        $workingCopyForPromotion = $nodeRepository->loadWorkingCopy($entityId);
        self::assertNotNull($workingCopyForPromotion);
        $promoteResult = $transitionService->transition($workingCopyForPromotion, 'publish', $editor);
        self::assertSame('published', $promoteResult->toState);

        $anonGetDoc4 = $anonController->show('node', $entityId);
        self::assertSame([], $anonGetDoc4->errors);
        \assert($anonGetDoc4->data instanceof JsonApiResource);
        self::assertSame('Patched draft title', $anonGetDoc4->data->attributes['title']);
        $anonBody4 = json_encode($anonGetDoc4->toArray(), JSON_THROW_ON_ERROR);
        self::assertNotSame($anonBody1, $anonBody4, 'Anonymous GET must now reflect the promoted content.');
    }

    #[Test]
    public function working_copy_param_403s_for_an_update_denied_account(): void
    {
        [$entityTypeManager, , $transitionService, $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $editor = $this->account(11, ['administer nodes', 'use editorial_forward transition publish']);
        $accountContext->set($editor);

        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $transitionService->transition($nodeRepository->find($entityId), 'publish', $editor);

        // View-capable (published content is publicly viewable), but NO edit
        // permission at all.
        $viewer = $this->account(12, ['access content']);
        $accountContext->set($viewer);
        $accessHandler = new EntityAccessHandler([
            new NodeAccessPolicy(),
            new PublishedContentAccessPolicy($entityTypeManager),
        ]);
        $controller = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $viewer),
            $accessHandler,
            $viewer,
        );

        // Sanity: the plain GET (no workingCopy) succeeds for this account.
        $plainGet = $controller->show('node', $entityId);
        self::assertSame([], $plainGet->errors);

        $doc = $controller->show('node', $entityId, ['workingCopy' => '1']);
        self::assertSame(403, $doc->statusCode, 'An update-denied account must get 403 for ?workingCopy=1, not the working copy.');
    }

    #[Test]
    public function working_copy_param_is_the_canonical_404_for_a_view_denied_account_byte_equal_to_a_missing_entity(): void
    {
        [$entityTypeManager, , , $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $author = $this->account(11, ['administer nodes']);
        $accountContext->set($author);

        // Never published: NodeAccessPolicy denies view for a non-owner with
        // no 'view own unpublished content' permission, and
        // PublishedContentAccessPolicy is Neutral (not published) — so the
        // entity-level view gate denies before ?workingCopy=1 is ever
        // consulted (R8 oracle standard, unchanged by this PR).
        $node = new Node(['title' => 'Draft only', 'type' => 'article', 'slug' => 'draft-only']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        self::assertSame('draft', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($nodeRepository->find($entityId)));

        $stranger = $this->account(13, []);
        $accountContext->set($stranger);
        $accessHandler = new EntityAccessHandler([
            new NodeAccessPolicy(),
            new PublishedContentAccessPolicy($entityTypeManager),
        ]);
        $controller = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $stranger),
            $accessHandler,
            $stranger,
        );

        // World A: the SAME probe id, but genuinely missing (mirrors
        // JsonApiControllerDeniedNotFoundTest's "same probe id through two
        // worlds" technique — a byte comparison across DIFFERENT ids would
        // spuriously fail on the id embedded in the detail message, which is
        // not what this test pins).
        $missingProbeController = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $stranger),
            $accessHandler,
            $stranger,
        );
        $missingDoc = $missingProbeController->show('node', $entityId . '00');
        self::assertSame(404, $missingDoc->statusCode, 'sanity: the probe id must not resolve to a real row');

        // World B: the SAME view-denied entity id, with and without the
        // ?workingCopy=1 param — the param must never change the 404 shape
        // (the view gate denies before the param is ever consulted).
        $plainDoc = $controller->show('node', $entityId);
        $workingCopyDoc = $controller->show('node', $entityId, ['workingCopy' => '1']);

        self::assertSame(404, $plainDoc->statusCode);
        self::assertSame(404, $workingCopyDoc->statusCode);
        self::assertSame(
            json_encode($plainDoc->toArray(), JSON_THROW_ON_ERROR),
            json_encode($workingCopyDoc->toArray(), JSON_THROW_ON_ERROR),
            '?workingCopy=1 must not change the 404 shape for a view-denied entity — no existence oracle.',
        );
    }

    #[Test]
    public function working_copy_param_equals_the_plain_get_byte_for_byte_when_no_draft_exists(): void
    {
        [$entityTypeManager, , $transitionService, $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $editor = $this->account(11, ['administer nodes', 'use editorial_forward transition publish']);
        $accountContext->set($editor);

        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $transitionService->transition($nodeRepository->find($entityId), 'publish', $editor);

        $accessHandler = new EntityAccessHandler([
            new NodeAccessPolicy(),
            new PublishedContentAccessPolicy($entityTypeManager),
        ]);
        $controller = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $editor),
            $accessHandler,
            $editor,
        );

        $plainGet = $controller->show('node', $entityId);
        $workingCopyGet = $controller->show('node', $entityId, ['workingCopy' => '1']);

        self::assertSame(json_encode($plainGet->toArray(), JSON_THROW_ON_ERROR), json_encode($workingCopyGet->toArray(), JSON_THROW_ON_ERROR), 'With no draft in flight, ?workingCopy=1 must equal the plain GET byte-for-byte.');
    }

    #[Test]
    public function patch_on_an_undisciplined_entity_is_byte_identical_to_pre_pr3_behavior(): void
    {
        // Regression pin (design §4/§11): for an undisciplined entity — never
        // bound to a workflow, so never pointered — loadWorkingCopy() ===
        // find(), so the PATCH target is unchanged from pre-PR-3 behavior.
        [$entityTypeManager, , , $accountContext] = $this->bootWiredProviders(bindWorkflow: false);
        $nodeRepository = $entityTypeManager->getRepository('node');

        $author = $this->account(11, ['administer nodes']);
        $accountContext->set($author);

        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();

        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $controller = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $author),
            $accessHandler,
            $author,
        );

        $patchDoc = $controller->update('node', $entityId, [
            'data' => ['type' => 'node', 'attributes' => ['title' => 'Edited without any workflow binding']],
        ]);
        self::assertSame(200, $patchDoc->statusCode);
        \assert($patchDoc->data instanceof JsonApiResource);
        self::assertSame('Edited without any workflow binding', $patchDoc->data->attributes['title']);

        $reloaded = $nodeRepository->find($entityId);
        self::assertNotNull($reloaded);
        self::assertSame('Edited without any workflow binding', $reloaded->get('title'), 'An unbound entity PATCH must land on the base row exactly as before PR-3.');
    }

    /**
     * @return array<string, mixed>
     */
    private function rawNodeRow(DBALDatabase $db, string $entityId): array
    {
        // Node's id column is `nid` (#[ContentEntityKeys(id: 'nid', ...)]),
        // not `id` — mirrors ForwardDraftFlowTest::rawBaseRow().
        $row = $db->getConnection()->fetchAssociative('SELECT * FROM node WHERE nid = ?', [$entityId]);
        self::assertIsArray($row, 'Base row must exist.');

        return $row;
    }

    /**
     * @param list<string> $permissions
     */
    private function account(int $id, array $permissions): AccountInterface
    {
        return new class ($id, $permissions) implements \Waaseyaa\Access\AuthorizationPrincipalInterface {
            public function __construct(private readonly int $accountId, private readonly array $permissions) {}
            public function id(): int|string { return $this->accountId; }
            public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return true; }
            public function claimsGeneration(): string { return 'workflow-pointer-test'; }
            public function tenantId(): ?string { return null; }
            public function communityId(): ?string { return null; }
        };
    }

    /**
     * Boot pattern copied from
     * {@see \Waaseyaa\Workflows\Tests\Integration\ForwardDraftFlowTest::bootWiredProviders()}
     * / {@see WriteAllowlistPointerBypassFlowTest::bootWiredProviders()}: the
     * REAL `NodeServiceProvider` + `WorkflowServiceProvider` wiring over real
     * SQLite, bound (by default) to a test-local `editorial_forward` workflow
     * carrying the `revise` (published -> draft) edge the shipped `editorial`
     * workflow does not yet ship (PR-5 territory).
     *
     * @return array{0: EntityTypeManager, 1: DBALDatabase, 2: TransitionService, 3: RequestAccountContext}
     */
    private function bootWiredProviders(bool $bindWorkflow = true): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configStorage = new MemoryStorage();
        if ($bindWorkflow) {
            $configStorage->write('workflows.assignments', [
                'node.article' => 'editorial_forward',
            ]);
        }
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

        if ($bindWorkflow) {
            $entityTypeManager->getRepository('workflow')->save($this->editorialForwardWorkflow());
        }

        $entityTypeManager->getRepository('node_type')->save(new NodeType(['type' => 'article', 'name' => 'Article']));

        /** @var TransitionService $transitionService */
        $transitionService = $workflowProvider->resolve(TransitionService::class);

        return [$entityTypeManager, $db, $transitionService, $accountContext];
    }

    /**
     * See {@see \Waaseyaa\Workflows\Tests\Integration\ForwardDraftFlowTest::editorialForwardWorkflow()}
     * for the full rationale — identical shape, duplicated here rather than
     * shared across packages.
     */
    private function editorialForwardWorkflow(): Workflow
    {
        $transitions = DefaultWorkflows::EDITORIAL['transitions'];
        $transitions['revise'] = ['label' => 'Revise', 'from' => ['published'], 'to' => 'draft'];

        foreach ($transitions as $id => $transition) {
            $transition['permission'] = \sprintf('use editorial_forward transition %s', $id);
            $transitions[$id] = $transition;
        }

        $workflow = new Workflow([
            'id' => 'editorial_forward',
            'label' => 'Editorial (test-local, forward drafts)',
            'initial_state' => DefaultWorkflows::EDITORIAL['initial_state'],
            'states' => DefaultWorkflows::EDITORIAL['states'],
            'transitions' => $transitions,
        ]);
        $workflow->enforceIsNew();

        return $workflow;
    }
}
