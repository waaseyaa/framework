<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Integration\Host;

use DateTimeImmutable;
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
use Waaseyaa\Api\Controller\WorkflowTransitionController;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\DateTime\FixedEntityClock;
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
use Waaseyaa\Node\NodeAuthorizationSnapshotReader;
use Waaseyaa\Node\NodeServiceProvider;
use Waaseyaa\Node\NodeType;
use Waaseyaa\Workflows\Access\WorkflowAuthorityAccessPolicy;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;
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
    public function browserCreatedNodeReceivesServerTimestampsAndSortsFirst(): void
    {
        [$entityTypeManager, , , $accountContext, $setAccessHandler] = $this->bootWiredProviders();
        $admin = $this->account(42, ['administer content', 'administer nodes', 'access content']);
        $accountContext->set($admin);
        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $setAccessHandler($accessHandler);

        $older = new Node([
            'title' => 'Older imported post',
            'type' => 'article',
            'slug' => 'older-imported-post',
            'created' => 1_700_000_000,
            'changed' => 1_700_000_000,
        ]);
        $older->enforceIsNew();
        $entityTypeManager->getRepository('node')->save($older);

        $host = new GenericAdminSurfaceHost(
            $entityTypeManager,
            $accessHandler,
            clock: new FixedEntityClock(new DateTimeImmutable('2026-07-22T14:15:16+00:00')),
        );
        $sessionRequest = Request::create('/admin');
        $sessionRequest->attributes->set('_account', $admin);
        self::assertNotNull($host->resolveSession($sessionRequest));

        $scope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard($scope, $accessHandler->checkProtectedFieldRead(...)));
        try {
            $created = $scope->run($admin, fn() => $host->action('node', 'create', ['attributes' => [
                'title' => 'Fresh browser post',
                'type' => 'article',
                'slug' => 'fresh-browser-post',
            ]]));

            self::assertTrue($created->ok, json_encode($created->error));
            self::assertSame('2026-07-22T14:15:16+00:00', $created->data['attributes']['created']);
            self::assertSame('2026-07-22T14:15:16+00:00', $created->data['attributes']['changed']);

            $listRequest = Request::create('/admin/_surface/node?sort=-created', 'GET');
            $listRequest->attributes->set('_account', $admin);
            $listed = $scope->run($admin, fn(): array => $host->handleList($listRequest, 'node'));
            self::assertTrue($listed['ok'], json_encode($listed));
            self::assertSame('Fresh browser post', $listed['data']['entities'][0]['attributes']['title']);

            $backdated = $scope->run($admin, fn() => $host->action('node', 'create', ['attributes' => [
                'title' => 'Backdated browser post',
                'type' => 'article',
                'slug' => 'backdated-browser-post',
                'created' => '2020-01-02T03:04:05+00:00',
            ]]));
            self::assertTrue($backdated->ok, json_encode($backdated->error));
            self::assertSame('2020-01-02T03:04:05+00:00', $backdated->data['attributes']['created']);
            self::assertSame('2026-07-22T14:15:16+00:00', $backdated->data['attributes']['changed']);
        } finally {
            EntityReadRuntime::installGuard(null);
        }
    }

    #[Test]
    public function browserShapedNodeListQueryPagesSortsAndFiltersMigratedRows(): void
    {
        [$entityTypeManager, $db, , $accountContext, $setAccessHandler] = $this->bootWiredProviders();
        $pageType = new NodeType(['type' => 'page', 'name' => 'Page']);
        $pageType->enforceIsNew();
        $entityTypeManager->getRepository('node_type')->save($pageType);

        $admin = $this->account(42, ['administer content', 'administer nodes', 'access content']);
        $accountContext->set($admin);
        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $setAccessHandler($accessHandler);
        $repository = $entityTypeManager->getRepository('node');

        for ($index = 0; $index < 30; ++$index) {
            $bundle = $index < 10 ? 'article' : 'page';
            $node = new Node([
                'title' => sprintf('Imported %s %02d', $bundle, $index),
                'type' => $bundle,
                'slug' => sprintf('imported-%s-%02d', $bundle, $index),
                'uid' => 42,
                'created' => 1_700_000_000 + $index,
            ]);
            $node->enforceIsNew();
            $repository->save($node);
        }
        $fresh = new Node([
            'title' => 'Fresh browser article',
            'type' => 'article',
            'slug' => 'fresh-browser-article',
            'uid' => 42,
            'created' => 1_800_000_000,
        ]);
        $fresh->enforceIsNew();
        $repository->save($fresh);

        // Mirror the imported database shape that exposed this regression:
        // host-unknown WordPress residue is retained in the base row's JSON bag.
        $connection = $db->getConnection();
        $raw = $connection->fetchOne('SELECT _data FROM node WHERE nid = ?', [$fresh->id()]);
        self::assertIsString($raw);
        $migratedData = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($migratedData);
        $migratedData['wp_status'] = 'publish';
        $connection->executeStatement(
            'UPDATE node SET _data = ? WHERE nid = ?',
            [json_encode($migratedData, JSON_THROW_ON_ERROR), $fresh->id()],
        );

        $host = new GenericAdminSurfaceHost($entityTypeManager, $accessHandler);
        $scope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard($scope, $accessHandler->checkProtectedFieldRead(...)));

        $request = static function (string $query) use ($admin): Request {
            $request = Request::create('/admin/_surface/node?' . $query, 'GET');
            $request->attributes->set('_account', $admin);

            return $request;
        };

        try {
            $pageOne = $scope->run($admin, fn(): array => $host->handleList(
                $request('page%5Boffset%5D=0&page%5Blimit%5D=25&sort=-created'),
                'node',
            ));
            $pageTwo = $scope->run($admin, fn(): array => $host->handleList(
                $request('page%5Boffset%5D=25&page%5Blimit%5D=25&sort=-created'),
                'node',
            ));
            $articles = $scope->run($admin, fn(): array => $host->handleList(
                $request('page%5Boffset%5D=0&page%5Blimit%5D=25&sort=-created&filter%5Btype%5D%5Boperator%5D=EQUALS&filter%5Btype%5D%5Bvalue%5D=article'),
                'node',
            ));
        } finally {
            EntityReadRuntime::installGuard(null);
        }

        self::assertTrue($pageOne['ok'], json_encode($pageOne));
        self::assertTrue($pageTwo['ok'], json_encode($pageTwo));
        self::assertTrue($articles['ok'], json_encode($articles));
        self::assertSame(31, $pageOne['data']['total']);
        self::assertSame('Fresh browser article', $pageOne['data']['entities'][0]['attributes']['title']);
        self::assertSame(25, $pageTwo['data']['offset']);
        self::assertSame(
            [],
            array_values(array_intersect(
                array_column($pageOne['data']['entities'], 'id'),
                array_column($pageTwo['data']['entities'], 'id'),
            )),
            'The second browser-shaped page request must not repeat page-one node rows.',
        );
        self::assertSame(11, $articles['data']['total']);
        self::assertSame(
            ['article'],
            array_values(array_unique(array_column(array_column($articles['data']['entities'], 'attributes'), 'type'))),
        );
    }

    #[Test]
    public function workflow_visibility_is_current_state_authenticated_view_only_and_additive(): void
    {
        [$entityTypeManager, , $transitionService, $accountContext] = $this->bootWiredProviders();
        $repository = $entityTypeManager->getRepository('node');

        $publisher = $this->account(90, ['use editorial transition publish']);
        $accountContext->set($publisher);
        $published = new Node([
            'title' => 'Published control',
            'type' => 'article',
            'slug' => 'published-control',
            'uid' => 90,
        ]);
        $published->enforceIsNew();
        $repository->save($published);
        $transitionService->transition($published, 'publish', $publisher);

        $draft = new Node([
            'title' => 'Draft controls',
            'type' => 'article',
            'slug' => 'draft-controls',
            'uid' => 42,
        ]);
        $draft->enforceIsNew();
        $repository->save($draft);

        $handler = new EntityAccessHandler([
            new NodeAccessPolicy(),
            new WorkflowAuthorityAccessPolicy($transitionService, $entityTypeManager),
        ]);

        $transitionOnly = $this->account(42, ['use editorial transition submit_for_review']);
        self::assertTrue($handler->check($draft, 'view', $transitionOnly)->isAllowed());
        self::assertFalse($handler->check($draft, 'update', $transitionOnly)->isAllowed());
        self::assertFalse($handler->check($draft, 'delete', $transitionOnly)->isAllowed());

        $wrongState = $this->account(43, ['use editorial transition archive']);
        self::assertFalse(
            $handler->check($draft, 'view', $wrongState)->isAllowed(),
            'a permission whose transition is outgoing only from published must not reveal a draft',
        );

        $forgedAnonymous = new AuthorizationPrincipal(
            0,
            false,
            ['editor'],
            ['use editorial transition submit_for_review'],
            'forged-anonymous',
        );
        self::assertFalse($handler->check($draft, 'view', $forgedAnonymous)->isAllowed());

        $ordinaryPublishedReader = $this->account(44, ['access content']);
        $reloadedPublished = $repository->find((string) $published->id());
        self::assertInstanceOf(Node::class, $reloadedPublished);
        self::assertTrue(
            $handler->check($reloadedPublished, 'view', $ordinaryPublishedReader)->isAllowed(),
            'the workflow policy must stay Neutral so ordinary published access remains additive',
        );
    }

    #[Test]
    public function unsatisfied_group_constraint_does_not_turn_transition_permission_into_visibility(): void
    {
        [$entityTypeManager, , $transitionService, $accountContext, , $configStorage] = $this->bootWiredProviders();

        $workflow = new Workflow([
            'id' => 'grouped_editorial',
            'label' => 'Grouped editorial',
            'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft', 'published' => false, 'default_revision' => false],
                'review' => ['label' => 'Review', 'published' => false, 'default_revision' => false],
            ],
            'transitions' => [
                'submit' => [
                    'label' => 'Submit',
                    'from' => ['draft'],
                    'to' => 'review',
                    'permission' => 'review grouped content',
                    'group_constraint' => 'content_groups',
                ],
            ],
        ]);
        $workflow->enforceIsNew();
        $entityTypeManager->getRepository('workflow')->save($workflow);
        $configStorage->write('workflows.assignments', ['node.article' => 'grouped_editorial']);

        $reviewer = $this->account(42, ['review grouped content']);
        $accountContext->set($reviewer);
        $draft = new Node([
            'title' => 'Department draft',
            'type' => 'article',
            'slug' => 'department-draft',
            'uid' => 7,
        ]);
        $draft->enforceIsNew();
        $entityTypeManager->getRepository('node')->save($draft);

        $handler = new EntityAccessHandler([
            new NodeAccessPolicy(),
            new WorkflowAuthorityAccessPolicy($transitionService, $entityTypeManager),
        ]);

        self::assertFalse(
            $handler->check($draft, 'view', $reviewer)->isAllowed(),
            'a missing group checker/content membership must fail closed even when the permission matches',
        );
    }

    #[Test]
    public function editor_can_complete_the_admin_create_view_list_edit_transition_loop_without_unsealing_fields(): void
    {
        [$entityTypeManager, , $transitionService, $accountContext, $setAccessHandler] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $publisher = $this->account(90, [
            'use editorial transition publish',
        ]);
        $accountContext->set($publisher);
        $migrated = new Node([
            'title' => 'Migrated published article',
            'type' => 'article',
            'slug' => 'migrated-published-article',
            'uid' => 90,
        ]);
        $migrated->enforceIsNew();
        $nodeRepository->save($migrated);
        $transitionService->transition($migrated, 'publish', $publisher);

        $editor = $this->account(42, [
            'administer content',
            'access content',
            'create article content',
            'edit own article content',
            'use editorial transition submit_for_review',
            'use editorial transition publish',
        ]);
        $accountContext->set($editor);

        $accessHandler = new EntityAccessHandler([
            new NodeAccessPolicy(),
            new WorkflowAuthorityAccessPolicy($transitionService, $entityTypeManager),
        ]);
        $setAccessHandler($accessHandler);
        $host = new GenericAdminSurfaceHost($entityTypeManager, $accessHandler);
        $sessionRequest = Request::create('/admin/_surface/session');
        $sessionRequest->attributes->set('_account', $editor);
        self::assertNotNull($host->resolveSession($sessionRequest));

        $scope = new AccountFieldReadScope();
        $principal = new AuthorizationPrincipal(
            42,
            true,
            ['editor'],
            [
                'administer content',
                'access content',
                'create article content',
                'edit own article content',
                'use editorial transition submit_for_review',
                'use editorial transition publish',
            ],
            'editor-loop-test',
        );
        EntityReadRuntime::installGuard(new FieldReadGuard($scope, $accessHandler->checkProtectedFieldRead(...)));

        try {
            $created = $scope->run($principal, fn() => $host->action('node', 'create', [
                'attributes' => [
                    'title' => 'Editor draft',
                    'type' => 'article',
                    'slug' => 'editor-draft',
                ],
            ]));
            self::assertTrue($created->ok, 'create: ' . json_encode($created->error));
            $draftUuid = (string) $created->data['id'];
            self::assertArrayNotHasKey('uid', $created->data['attributes']);
            self::assertArrayNotHasKey('status', $created->data['attributes']);
            self::assertArrayNotHasKey('workflow_state', $created->data['attributes']);

            $detail = $scope->run($principal, fn() => $host->get('node', $draftUuid));
            self::assertTrue($detail->ok, 'detail: ' . json_encode($detail->error));
            self::assertSame('Editor draft', $detail->data['attributes']['title']);

            $list = $scope->run($principal, fn() => $host->list('node'));
            self::assertTrue($list->ok, 'list: ' . json_encode($list->error));
            self::assertSame(2, $list->data['total']);
            self::assertEqualsCanonicalizing(
                [$draftUuid, $migrated->uuid()],
                array_column($list->data['entities'], 'id'),
                'the current draft and ordinary access-content published node must both be listed',
            );

            $schema = $scope->run($principal, fn() => $host->action('node', 'schema', ['id' => $draftUuid]));
            self::assertTrue($schema->ok, 'edit schema: ' . json_encode($schema->error));

            $edited = $scope->run($principal, fn() => $host->action('node', 'update', [
                'id' => $draftUuid,
                'mutation_token' => $detail->data['mutation_token'],
                'attributes' => ['title' => 'Editor draft revised'],
            ]));
            self::assertTrue($edited->ok, 'edit: ' . json_encode($edited->error));

            $workflowController = new WorkflowTransitionController($entityTypeManager, $accessHandler, $transitionService);
            $discoveryRequest = Request::create("/api/node/{$draftUuid}/workflow/transitions", 'GET');
            $discoveryRequest->attributes->set('_account', $editor);
            $discovery = $scope->run($principal, fn() => $workflowController->transitions($discoveryRequest, 'node', $draftUuid));
            self::assertSame(200, $discovery->getStatusCode());
            $discoveryBody = json_decode((string) $discovery->getContent(), true, 16, JSON_THROW_ON_ERROR);
            self::assertContains('submit_for_review', array_column($discoveryBody['data'], 'id'));
            self::assertNull($discoveryBody['meta']['workflow_state'], 'sealed workflow state must remain omitted');

            $submitRequest = Request::create(
                "/api/node/{$draftUuid}/workflow/transition",
                'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: '{"transition":"submit_for_review"}',
            );
            $submitRequest->attributes->set('_account', $editor);
            $submitted = $scope->run($principal, fn() => $workflowController->transition($submitRequest, 'node', $draftUuid));
            self::assertSame(200, $submitted->getStatusCode());

            $reviewDiscoveryRequest = Request::create("/api/node/{$draftUuid}/workflow/transitions", 'GET');
            $reviewDiscoveryRequest->attributes->set('_account', $editor);
            $reviewDiscovery = $scope->run($principal, fn() => $workflowController->transitions($reviewDiscoveryRequest, 'node', $draftUuid));
            $reviewBody = json_decode((string) $reviewDiscovery->getContent(), true, 16, JSON_THROW_ON_ERROR);
            self::assertSame(['publish'], array_column($reviewBody['data'], 'id'));

            $publishRequest = Request::create(
                "/api/node/{$draftUuid}/workflow/transition",
                'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: '{"transition":"publish"}',
            );
            $publishRequest->attributes->set('_account', $editor);
            $published = $scope->run($principal, fn() => $workflowController->transition($publishRequest, 'node', $draftUuid));
            self::assertSame(200, $published->getStatusCode());

            $final = $scope->run($principal, fn() => $host->get('node', $draftUuid));
            self::assertTrue($final->ok, 'published detail: ' . json_encode($final->error));
            self::assertSame('Editor draft revised', $final->data['attributes']['title']);
            self::assertArrayNotHasKey('uid', $final->data['attributes']);
            self::assertArrayNotHasKey('status', $final->data['attributes']);
            self::assertArrayNotHasKey('workflow_state', $final->data['attributes']);
        } finally {
            EntityReadRuntime::installGuard(null);
        }
    }

    #[Test]
    public function admin_created_draft_is_attributed_to_the_authenticated_creator(): void
    {
        [$entityTypeManager, , , $accountContext] = $this->bootWiredProviders();

        $creator = $this->account(42, [
            'administer content',
            'create article content',
            'view own unpublished content',
        ]);
        $accountContext->set($creator);

        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $host = new GenericAdminSurfaceHost($entityTypeManager, $accessHandler);
        $request = Request::create('/');
        $request->attributes->set('_account', $creator);
        self::assertNotNull($host->resolveSession($request));

        $created = $host->action('node', 'create', [
            'attributes' => [
                'title' => 'Creator-owned draft',
                'type' => 'article',
            ],
        ]);

        self::assertTrue($created->ok, 'admin create must succeed: ' . json_encode($created->error));
        $ids = $entityTypeManager->getRepository('node')->getQuery()
            ->accessCheck(false)
            ->condition('uuid', (string) $created->data['id'])
            ->execute();
        self::assertCount(1, $ids);
        $draft = $entityTypeManager->getRepository('node')->find((string) $ids[0]);
        self::assertInstanceOf(Node::class, $draft);
        self::assertSame(
            42,
            (int) (new NodeAuthorizationSnapshotReader())->read($draft)->authorId,
            'the authenticated creator must own the persisted draft',
        );
    }

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
                'mutation_token' => $getResult->data['mutation_token'],
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
        return new AuthorizationPrincipal($id, true, [], $permissions, 'test-' . $id);
    }

    /**
     * Boot pattern copied from
     * {@see \Waaseyaa\Api\Tests\Integration\WriteAllowlistPointerBypassFlowTest::bootWiredProviders()}.
     *
     * @return array{0: EntityTypeManager, 1: DBALDatabase, 2: TransitionService, 3: RequestAccountContext, 4: \Closure(EntityAccessHandler): void, 5: MemoryStorage}
     */
    private function bootWiredProviders(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();
        $liveAccessHandler = null;

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            'node.article' => 'editorial',
        ]);
        $configFactory = new ConfigFactory($configStorage, $dispatcher);

        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $db, &$liveAccessHandler): EntityRepositoryInterface {
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
                accessHandlerResolver: static function () use (&$liveAccessHandler): ?EntityAccessHandler {
                    return $liveAccessHandler;
                },
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

        $articleType = new NodeType(['type' => 'article', 'name' => 'Article']);
        $articleType->enforceIsNew();
        $entityTypeManager->getRepository('node_type')->save($articleType);

        /** @var TransitionService $transitionService */
        $transitionService = $workflowProvider->resolve(TransitionService::class);

        $setAccessHandler = static function (EntityAccessHandler $handler) use (&$liveAccessHandler): void {
            $liveAccessHandler = $handler;
        };

        return [$entityTypeManager, $db, $transitionService, $accountContext, $setAccessHandler, $configStorage];
    }
}
