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
 *
 * **PR-4 rework (Drupal JSON:API parity — echo-tolerant rejection).** A
 * fresh-context review found the pre-rework guard's hard, unconditional
 * refusal was itself a BLOCKER: `ResourceSerializer` emits `revision_id`/
 * `published_revision_id` as ordinary read attributes (FR-008,
 * `docs/specs/api-layer.md` "revision_id is a load-bearing read attribute"),
 * and the admin SPA's `SchemaForm.vue` submits the FULL loaded attribute
 * object back on save — so the hard reject 422s every ordinary node edit
 * through the admin UI. {@see \Waaseyaa\Entity\Write\EntityWritePayloadGuard::evaluateForUpdate()}
 * fixes this: a submitted identity/bookkeeping key is refused ONLY when its
 * value DIFFERS from the entity's current stored value; a pure echo passes
 * but is stripped before the apply loop (belt — even an allowed echo must
 * never reach `$entity->set()`). `eve_cannot_move_the_published_pointer_through_a_patch_body()`
 * below re-pins the differing-value security core against the new logic (it
 * still 422s — Eve's submitted `published_revision_id` is a DIFFERENT value
 * than the live pointer); the round-trip pin and echo-acceptance tests below
 * it are the new coverage this rework adds.
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

        self::assertSame('archived', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($nodeRepository->find($entityId)));

        $beforeRow = $this->rawNodeRow($db, $entityId);
        self::assertNotSame($revP, (int) $beforeRow['published_revision_id'], 'sanity: archive must have moved the pointer past rev_P');
        $revA = (int) $beforeRow['published_revision_id'];

        // Eve: entity update access, ZERO workflow/publish permission.
        $eve = $this->account(12, ['edit any article content']);
        $accountContext->set($eve);
        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $controller = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $eve),
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
        $controller = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $eve),
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
        $controller = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $eve),
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
        $gatedController = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $gated),
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
        $permittedController = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $permitted),
            $accessHandler,
            $permitted,
        );
        $permittedDoc = $permittedController->update('node', $entityId, [
            'data' => ['type' => 'node', 'attributes' => ['status' => 0]],
        ]);
        self::assertSame(200, $permittedDoc->statusCode);
    }

    // --- PR-4 rework: the round-trip pin (the missing test the BLOCKER review found) ---

    #[Test]
    public function full_attribute_round_trip_patch_persists_the_changed_title_and_the_pointer_stays_self_consistent(): void
    {
        // The admin-SPA-shaped oracle: GET a node via the serializer (real
        // JsonApiController::show(), not a hand-built fixture), then PATCH the
        // FULL loaded attribute set back with one field changed — exactly
        // what SchemaForm.vue does (`formData.value = { ...entityResult.value.attributes }`
        // then `update(props.entityType, props.entityId, formData.value)`).
        // Before the PR-4 rework this 422s on `revision_id`/`published_revision_id`
        // (both real, undeclared bookkeeping columns the serializer emits as
        // ordinary read attributes — FR-008). After the rework: a pure echo
        // of those columns passes and is stripped before apply, so the PATCH
        // 200s with the title change persisted.
        //
        // Post-rebase note (PR-2, #1920, same anchor issue): once a node
        // carries a published pointer it is "default-revision-disciplined"
        // for every subsequent save (`WorkflowStateGuard::setDiscipline()`) —
        // an AUTHORIZED same-state edit of already-published content
        // legitimately RE-PUBLISHES what it just saved (same-state
        // republish, `docs/specs/content-workflow.md` "Default-revision
        // discipline"), through the `setPublishedRevision()` choke point,
        // independent of anything in the PATCH body. So the published
        // pointer is NOT expected to stay byte-unmoved here (that would
        // actually be the OLD, pre-option-1 behavior) — the invariant this
        // test pins is that the pointer ends up SELF-CONSISTENT with the new
        // tip (a legitimate engine-driven promotion), never diverging from
        // it, regardless of what the client happened to echo back for
        // `published_revision_id`/`revision_id`. The DIFFERING-value security
        // core (an unauthorized or arbitrary pointer value never applies) is
        // pinned separately by `eve_cannot_move_the_published_pointer_through_a_patch_body()`.
        [$entityTypeManager, $db, $transitionService, $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        // 'administer nodes' isolates this test to the write-allowlist
        // guard's own behavior: NodeAccessPolicy's PUBLISH_GATED_FIELDS
        // (status/workflow_state) and ADMIN_ONLY_EDIT_FIELDS (uid/type/
        // created/changed) are pre-existing, unrelated field-access gates
        // that would also reject a non-admin's full-attribute echo — real
        // friction, but not what this test pins. 'use editorial transition
        // publish' additionally satisfies the same-state republish any-of
        // authorization check above.
        $admin = $this->account(21, ['administer nodes', 'use editorial transition publish']);
        $accountContext->set($admin);

        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $publishResult = $transitionService->transition($nodeRepository->find($entityId), 'publish', $admin);
        self::assertSame('published', $publishResult->toState);

        $beforeRow = $this->rawNodeRow($db, $entityId);
        self::assertGreaterThan(0, (int) $beforeRow['published_revision_id'], 'sanity: the node must carry a real published pointer before the round trip');
        self::assertSame($beforeRow['revision_id'], $beforeRow['published_revision_id'], 'sanity: a freshly-published node is self-consistent (tip === published)');

        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $controller = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $admin),
            $accessHandler,
            $admin,
        );

        // The "GET" a real client performs before editing.
        $getDoc = $controller->show('node', $entityId);
        self::assertSame([], $getDoc->errors);
        \assert($getDoc->data instanceof JsonApiResource);
        $loadedAttributes = $getDoc->data->attributes;
        self::assertArrayHasKey('revision_id', $loadedAttributes, 'sanity: revision_id must ride the read attributes (FR-008)');
        self::assertArrayNotHasKey('published_revision_id', $loadedAttributes, 'Internal publication pointers must not ride an ordinary account projection.');
        self::assertSame((int) $beforeRow['revision_id'], (int) $loadedAttributes['revision_id']);

        // SchemaForm.vue's exact shape: the FULL loaded attribute object,
        // with one field changed.
        $patchAttributes = $loadedAttributes;
        $patchAttributes['title'] = 'Edited via full round trip';

        $patchDoc = $controller->update('node', $entityId, [
            'data' => ['type' => 'node', 'attributes' => $patchAttributes],
        ]);
        $array = $patchDoc->toArray();
        self::assertSame(200, $patchDoc->statusCode, 'a full-attribute echo PATCH of a real node must not 422: ' . json_encode($array));
        self::assertSame('Edited via full round trip', $array['data']['attributes']['title']);

        $afterRow = $this->rawNodeRow($db, $entityId);
        self::assertGreaterThan((int) $beforeRow['revision_id'], (int) $afterRow['revision_id'], 'sanity: the edit must have cut a new revision');
        self::assertSame(
            $afterRow['revision_id'],
            $afterRow['published_revision_id'],
            'the published pointer must stay self-consistent with the new tip (legitimate same-state republish) — never a stray value',
        );
    }

    #[Test]
    public function echo_equal_published_revision_id_is_accepted_and_the_pointer_is_not_rewritten_by_an_ordinary_edit(): void
    {
        // Test #3 of the rework brief: an echo of published_revision_id must
        // 200 (not 422), and the value must provably NOT be rewritten by the
        // save — the strip-before-apply "belt" proven, not merely asserted.
        //
        // Scenario choice (post-PR-2-rebase finding, empirically verified —
        // see the round-trip pin test above for the disciplined case): once a
        // node carries a published pointer it is "default-revision-
        // disciplined" for every later save, and an authorized same-state
        // edit of already-published content LEGITIMATELY re-publishes what
        // it just saved (same-state republish) — the published pointer is
        // then EXPECTED to advance, not stay put, regardless of the guard.
        // To pin "the echo is provably not rewritten by THIS save" rather
        // than by a separate, independent engine mechanism, this test uses a
        // NEVER-published node: `published_revision_id` is null, discipline
        // never engages (`WorkflowStateGuard::setDiscipline()` reads
        // `loadPublishedRevision() !== null` — false here), so
        // `EntityRepository::doSave()` writes the base row directly from the
        // entity's value bag with no independent pointer mechanism riding
        // along. If strip-before-apply did NOT run and the echoed null
        // instead flowed through `$entity->set()` into that value bag
        // (`docs/specs/content-workflow.md`'s documented gotcha — `find()`
        // hydrates `published_revision_id` into `toArray()` even though it
        // carries no field definition), the column would still ride along on
        // every ordinary edit rather than being a guard-independent,
        // structural non-write — this test proves it does not.
        [$entityTypeManager, $db, , $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $editor = $this->account(22, ['create article content', 'edit any article content']);
        $accountContext->set($editor);

        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();

        $beforeRow = $this->rawNodeRow($db, $entityId);
        self::assertNull($beforeRow['published_revision_id'], 'sanity: a never-published node carries no published pointer');

        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $controller = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $editor),
            $accessHandler,
            $editor,
        );

        // Echo the CURRENT (null) published pointer alongside a genuine
        // content edit — the ordinary "read, tweak one field, save everything
        // back" shape.
        $doc = $controller->update('node', $entityId, [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'Edited never-published',
                    'published_revision_id' => null,
                ],
            ],
        ]);
        $array = $doc->toArray();

        self::assertSame(200, $doc->statusCode, 'a null echo of the (null) published pointer must be accepted, not refused: ' . json_encode($array));
        self::assertSame('Edited never-published', $array['data']['attributes']['title']);

        $afterRow = $this->rawNodeRow($db, $entityId);
        self::assertNull(
            $afterRow['published_revision_id'],
            'the published pointer must be provably unmoved (still null) by the accepted echo PATCH',
        );
        // The content edit legitimately cut a NEW revision (C-22 WP3): the
        // tip pointer advances even though the PUBLISHED pointer does not —
        // proving this is a real, mutating save, not a no-op the assertion
        // above would pass trivially.
        self::assertGreaterThan((int) $beforeRow['revision_id'], (int) $afterRow['revision_id'], 'sanity: the edit must have cut a new revision');
    }

    // --- MINOR (same review): store()'s per-bundle create-access fix ---

    #[Test]
    public function account_with_only_the_type_level_create_permission_cannot_create_an_article(): void
    {
        // MINOR finding from the same review: store()'s pre-existing bundle
        // resolution bug read the literal attribute key 'bundle' (almost
        // never node's real bundle key, 'type'), so it silently fell back to
        // checkCreateAccess('node', 'node', ...) — the TYPE-level permission
        // 'create node content' — for any real client. The PR-4 rework fixed
        // the bundle resolution to use the entity type's OWN bundle key,
        // which means checkCreateAccess('node', 'article', ...) now checks
        // the BUNDLE-level 'create article content' permission instead. An
        // account holding only the type-level permission that used to work
        // via the bug must now be denied (403) for a real bundle create.
        [$entityTypeManager, , , $accountContext] = $this->bootWiredProviders();

        $typeLevelOnly = $this->account(31, ['create node content']);
        $accountContext->set($typeLevelOnly);
        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $controller = new AccountScopedJsonApiController(
            new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager), $accessHandler, $typeLevelOnly),
            $accessHandler,
            $typeLevelOnly,
        );

        $doc = $controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => ['title' => 'Should be denied', 'type' => 'article', 'slug' => 'should-be-denied'],
            ],
        ]);

        self::assertSame(403, $doc->statusCode);
        self::assertSame([], $entityTypeManager->getRepository('node')->findBy([]), 'a denied create must persist nothing');
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
        return new class ($id, $permissions) implements \Waaseyaa\Access\AuthorizationPrincipalInterface {
            public function __construct(private readonly int $accountId, private readonly array $permissions) {}
            public function id(): int|string { return $this->accountId; }
            public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return true; }
            public function claimsGeneration(): string { return 'workflow-write-test'; }
            public function tenantId(): ?string { return null; }
            public function communityId(): ?string { return null; }
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
