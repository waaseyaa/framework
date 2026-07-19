<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
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
use Waaseyaa\Workflows\DefaultWorkflows;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * Required integration test (CW-v1 WP-2 task 2.6, #1920,
 * docs/specs/content-workflow.md "forward draft" / "two-pointer status
 * semantics"), UPDATED for CW-v1 option-1 (#1920 PR-2, design §1/§3.1):
 * these fixture entity types are workflow-bound, so once a published
 * pointer exists they are DISCIPLINED — `find()` serves the BASE ROW
 * (the published pointer's content), not the tip; `loadWorkingCopy()` is
 * the pointer-aware alternative that serves the draft. Every assertion
 * below that reads "the current/tip row" now sources it via
 * `loadWorkingCopy()`, and `find()` assertions were added/inverted to
 * prove the base row stays byte-stable during a draft window — the
 * doctrine inversion this rebuild exists to make (see
 * `ForwardDraftFlowTest` for the full Node-based spine).
 *
 * The forward-draft flow end-to-end, through the REAL kernel
 * wiring — real dispatcher, real SQLite-backed `EntityRepository`, a REAL
 * `WorkflowServiceProvider::boot()` (proving `WorkflowStateGuard` AND
 * `WorkflowPointerMoveGuard` are both live on the same dispatcher the
 * repository saves through), mirroring {@see GuardWiringTest}'s wiring
 * style. An ambient account context ({@see RequestAccountContext}) is served
 * through the kernel-services bus so both guards run their permission checks
 * for real.
 *
 * The shipped `editorial` workflow (seeded by `boot()` from
 * `DefaultWorkflows::EDITORIAL`) no longer ships a `revise` published ->
 * draft forward-draft entry edge (WP-2 rework, #1920: forward drafts
 * deferred — see the package README). This test proves the ENGINE mechanics
 * (the forward-draft branch in `TransitionService`/`WorkflowStateGuard`
 * stays live substrate, reachable via a custom workflow), so the fixture
 * entity types here are bound instead to a test-local `editorial_forward`
 * workflow — the descoped shipped shape plus a `revise` transition,
 * persisted directly by {@see self::bootWiredProvider()} — carrying the
 * whole story with no synthetic edges beyond that one addition.
 *
 * Scenario (task 2.6 brief, verbatim): publish a node -> edit via raw save
 * into 'draft' (forward draft) -> assert the public read path (published
 * pointer + status) still serves the OLD content and status=1 -> transition
 * the draft revision to 'published' -> assert the pointer moved and the new
 * content is live. Plus the denial leg: an account holding no
 * transition-into-'published' permission attempting a direct pointer
 * promotion is denied by the wired pointer guard, leaving BOTH the persisted
 * `status` and `published_revision_id` unchanged.
 *
 * Uses a synthetic revisionable entity type rather than real `Node`
 * deliberately: the flow under test is engine mechanics (workflows L3 +
 * entity-storage L1); pulling in `node` would add its bundle/NodeType
 * listener surface without exercising any additional task-2.6 code path
 * (Node's revision wiring is Tasks 2.1–2.3's coverage, and the full-stack
 * node spine is Task 2.8's).
 */
#[CoversNothing]
final class ForwardDraftIntegrationTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'forward_draft_subject';
    private const string REVISIONING_ENTITY_TYPE_ID = 'forward_draft_revisioning';

    #[Test]
    public function forward_draft_on_published_content_leaves_the_live_version_serving_until_republished(): void
    {
        [$entityTypeManager, $provider, $accountContext] = $this->bootWiredProvider();
        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);
        $transitionService = $provider->resolve(TransitionService::class);

        $editor = $this->account(7, ['use editorial_forward transition publish', 'use editorial_forward transition revise']);
        $accountContext->set($editor);

        // --- 1. Create + publish (on the test-local `editorial_forward` workflow). ---
        $entity = new ForwardDraftSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'draft', 'title' => 'Original title'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $repository->save($entity);
        $entityId = (string) $entity->id();

        $transitionService->transition($entity, 'publish', $editor);

        $publishedRevisionId = (int) $entity->get('revision_id');
        $publishedPointer = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($publishedPointer);
        $this->assertSame($publishedRevisionId, (int) $publishedPointer->get('revision_id'));
        $this->assertSame('Original title', $publishedPointer->get('title'));
        $this->assertSame(1, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($publishedPointer));

        // --- 2. Raw-save forward draft (NOT through TransitionService): ---
        // edit the current tip with new content, moving ITS OWN
        // workflow_state to 'draft' via the test-local `editorial_forward`
        // workflow's 'revise' edge (published -> draft; the shipped
        // `editorial` workflow no longer carries this edge — WP-2 rework,
        // forward drafts deferred; the acting account holds its permission).
        // WorkflowStateGuard fires on this save (task 2.6): it must NOT
        // flip status to 'draft'.published (false => 0) since a published
        // pointer already exists.
        $tip = $repository->find($entityId);
        $this->assertNotNull($tip);
        $tip->setNewRevision(true);
        $tip->set('title', 'Forward draft title');
        $tip->set('workflow_state', 'draft');
        $repository->save($tip);
        $draftRevisionId = (int) $tip->get('revision_id');
        $this->assertNotSame($publishedRevisionId, $draftRevisionId);

        // --- 3. Public read path: published pointer + status must still ---
        // serve the OLD content, completely untouched by the forward draft.
        $stillLive = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($stillLive);
        $this->assertSame($publishedRevisionId, (int) $stillLive->get('revision_id'));
        $this->assertSame('Original title', $stillLive->get('title'));
        $this->assertSame(1, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($stillLive));

        // CW-v1 option-1 (#1920 PR-2): find() itself must stay
        // byte-stable — it keeps serving the PUBLISHED base row, not the
        // draft tip, because this entity is now DISCIPLINED (bound +
        // published pointer).
        $servedRow = $repository->find($entityId);
        $this->assertNotNull($servedRow);
        $this->assertSame('published', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($servedRow));
        $this->assertSame('Original title', $servedRow->get('title'));
        $this->assertSame(1, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($servedRow));

        // The current/tip row (what an editor sees) — loadWorkingCopy(),
        // NOT find() — is the new draft, and its own `status` was
        // preserved (copied from the published pointer), not flipped to
        // the 'draft' state's published flag (0).
        $currentTip = $repository->loadWorkingCopy($entityId);
        $this->assertNotNull($currentTip);
        $this->assertSame('draft', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($currentTip));
        $this->assertSame('Forward draft title', $currentTip->get('title'));
        $this->assertSame(1, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($currentTip));

        // --- 4. Publish the draft revision through TransitionService: ---
        // the pointer must move and the new content becomes live. The wired
        // pointer guard sees a SAME-state move (published -> published: the
        // service saves the 'published' tip first, then moves the pointer)
        // and allows it because the acting account holds 'publish' — a
        // transition into 'published'.
        $transitionService->transition($currentTip, 'publish', $editor);

        $newlyLive = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($newlyLive);
        $this->assertSame('Forward draft title', $newlyLive->get('title'));
        $this->assertSame(1, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($newlyLive));
        $newLiveRevisionId = (int) $newlyLive->get('revision_id');
        $this->assertNotSame($publishedRevisionId, $newLiveRevisionId);

        // --- 5. Denial leg (real guard, real DB): an account holding NO ---
        // transition-into-'published' permission attempts a direct pointer
        // promotion (resurrecting the OLD published revision — a same-state
        // published -> published move). The wired WorkflowPointerMoveGuard
        // must deny it BEFORE any write: both the persisted published
        // pointer AND the base row's status must be unchanged afterward.
        $accountContext->set($this->account(8, []));

        $denied = null;
        try {
            $repository->setPublishedRevision($entityId, $publishedRevisionId);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $denied->reason);

        $afterDenial = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($afterDenial);
        $this->assertSame($newLiveRevisionId, (int) $afterDenial->get('revision_id'), 'Denied pointer move must leave published_revision_id unchanged.');
        $this->assertSame('Forward draft title', $afterDenial->get('title'));
        $baseRow = $repository->find($entityId);
        $this->assertNotNull($baseRow);
        $this->assertSame(1, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($baseRow), 'Denied pointer move must leave the persisted status unchanged.');
    }

    #[Test]
    public function archived_content_is_republishable_via_restore_forward_draft_and_publish(): void
    {
        // CW-v1 WP-2 task 2.6 re-review (#1920): archived content must not
        // be a dead end. This scenario uses only archive/restore/
        // restore_to_published — edges the shipped `editorial` workflow
        // still carries unchanged (only `revise` was descoped) — so it
        // exercises the same shape on the test-local `editorial_forward`
        // workflow this file binds to. Full flow:
        // publish -> archive -> restore (forward draft; pointer stays on
        // the archived revision) -> edit -> publish (the pointer move is an
        // archived -> published different-state move, satisfied by the
        // 'restore_to_published' edge).
        //
        // Permissions the full flow needs (all on the acting account, which
        // is both the TransitionService $account argument and the ambient
        // context the guards check):
        //   - 'use editorial_forward transition publish'   (first publish + the final
        //     draft -> published transition/save)
        //   - 'use editorial_forward transition archive'   (archive transition + its
        //     archived-pointer move)
        //   - 'use editorial_forward transition restore'   (restore transition's
        //     archived -> draft save)
        //   - 'use editorial_forward transition restore_to_published' (the FINAL
        //     pointer move: the guard sees archived -> published, which is
        //     that edge — the 'publish' permission alone does not cover it)
        [$entityTypeManager, $provider, $accountContext] = $this->bootWiredProvider();
        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);
        $transitionService = $provider->resolve(TransitionService::class);

        $editor = $this->account(7, [
            'use editorial_forward transition publish',
            'use editorial_forward transition archive',
            'use editorial_forward transition restore',
            'use editorial_forward transition restore_to_published',
        ]);
        $accountContext->set($editor);

        // Publish, then archive.
        $entity = new ForwardDraftSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'draft', 'title' => 'Live once'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $repository->save($entity);
        $entityId = (string) $entity->id();

        $transitionService->transition($entity, 'publish', $editor);
        $transitionService->transition($entity, 'archive', $editor);

        $archivedPointer = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($archivedPointer);
        $this->assertSame('archived', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($archivedPointer));
        $this->assertSame(0, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($repository->find($entityId)));

        // Restore: a forward draft — the pointer stays on the archived
        // revision, status stays 0 (copied from the archived pointer).
        $transitionService->transition($entity, 'restore', $editor);
        $stillArchived = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($stillArchived);
        $this->assertSame('archived', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($stillArchived));
        $this->assertSame(0, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($repository->find($entityId)));

        // Edit the restored draft, then publish it. The transition itself
        // validates draft -> published ('publish'); the pointer move the
        // service then performs is archived -> published, satisfied by
        // 'restore_to_published'.
        //
        // CW-v1 option-1 (#1920 PR-2): find() still serves the ARCHIVED
        // base row (byte-stable) — loadWorkingCopy() is what serves the
        // restored draft.
        $tip = $repository->loadWorkingCopy($entityId);
        $this->assertNotNull($tip);
        $this->assertSame('draft', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($tip));
        $tip->setNewRevision(true);
        $tip->set('title', 'Live again');
        $repository->save($tip);

        $transitionService->transition($tip, 'publish', $editor);

        // End state: pointer on the republished revision, new content live,
        // status back to 1.
        $republished = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($republished);
        $this->assertSame('published', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($republished));
        $this->assertSame('Live again', $republished->get('title'));
        $this->assertSame(1, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($republished));
        $this->assertSame(1, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($repository->find($entityId)));
    }

    #[Test]
    public function a_guard_denied_promotion_leaves_persisted_status_and_pointer_unchanged(): void
    {
        // CW-v1 WP-2 task 2.6 panel finding A+C (#1920): the denial-ordering
        // guarantee proven against a REAL DB with BOTH wired guards, not a
        // spy. Scenario: archived pointer (status 0), account holds
        // 'publish' but NOT 'restore_to_published'. transition('publish')
        // passes the transition-level check (draft -> published), commits
        // the revision-creating save, then the pointer guard denies the
        // archived -> published pointer move (REASON_PERMISSION). The
        // persisted base row must afterwards show status STILL 0 and
        // published_revision_id STILL the archived revision — in
        // particular, WorkflowStateGuard must NOT have flipped status to 1
        // during the first save (it fires on that save; before this fix its
        // defaultRevision-true branch set status from the target state's
        // published flag, committing status=1 with the pointer stuck on
        // archived).
        //
        // Known, accepted residue (documented in the spec): the denied
        // attempt leaves an ORPHAN TIP REVISION — a 'published'-stamped
        // revision that was saved but never promoted. It carries the
        // pointer-derived status (0), is invisible to the public read path
        // (the pointer never moved), and a later successful transition
        // simply supersedes it.
        [$entityTypeManager, $provider, $accountContext] = $this->bootWiredProvider();
        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);
        $transitionService = $provider->resolve(TransitionService::class);

        $editor = $this->account(7, [
            'use editorial_forward transition publish',
            'use editorial_forward transition archive',
            'use editorial_forward transition restore',
            // NO 'use editorial_forward transition restore_to_published'.
        ]);
        $accountContext->set($editor);

        $entity = new ForwardDraftSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'draft', 'title' => 'Was live'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $repository->save($entity);
        $entityId = (string) $entity->id();

        $transitionService->transition($entity, 'publish', $editor);
        $transitionService->transition($entity, 'archive', $editor);
        $transitionService->transition($entity, 'restore', $editor);

        $archivedPointer = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($archivedPointer);
        $archivedRevisionId = (int) $archivedPointer->get('revision_id');
        $this->assertSame(0, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($repository->find($entityId)));

        // The restored draft tip; publishing it needs restore_to_published
        // for the pointer move, which this account lacks.
        //
        // CW-v1 option-1 (#1920 PR-2): find() serves the ARCHIVED base row
        // (byte-stable) here, not the restored draft — loadWorkingCopy()
        // is required to get the real tip. Passing find()'s stale
        // (archived-pointer) revision id to transition() would now
        // legitimately trip TransitionService's own deterministic content
        // rule (RevisionConflictException) before ever reaching the
        // pointer-guard denial this test is about.
        $tip = $repository->loadWorkingCopy($entityId);
        $this->assertNotNull($tip);

        $denied = null;
        try {
            $transitionService->transition($tip, 'publish', $editor);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $denied->reason);

        // FRESH reads: base-row status unchanged (0 — archived semantics),
        // published pointer unchanged (still the archived revision).
        $freshBase = $repository->find($entityId);
        $this->assertNotNull($freshBase);
        $this->assertSame(0, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($freshBase), 'Denied pointer move must not leave status flipped in the base row.');

        $freshPointer = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($freshPointer);
        $this->assertSame($archivedRevisionId, (int) $freshPointer->get('revision_id'), 'Denied pointer move must leave published_revision_id unchanged.');
        $this->assertSame('archived', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($freshPointer));
    }

    #[Test]
    public function forward_draft_on_a_no_new_revision_bundle_still_creates_a_revision_instead_of_clobbering_the_published_one(): void
    {
        // CW-v1 WP-2 task 2.6 panel finding B (#1920): the synthetic entity
        // type here has revisionDefault: false (the EntityType default) —
        // the exact shape of a `new_revision: false` bundle. A raw API-path
        // forward draft (state-changing save via the test-local
        // `editorial_forward` workflow's 'revise' edge, caller sets NO
        // setNewRevision) previously updated the published
        // revision IN PLACE: draft title AND workflow_state='draft' written
        // into the very row the published pointer serves. The guard must
        // force a new revision for state-changing forward drafts — bundle
        // opt-out governs ordinary edits only.
        [$entityTypeManager, $provider, $accountContext] = $this->bootWiredProvider();
        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);
        $transitionService = $provider->resolve(TransitionService::class);

        $editor = $this->account(7, ['use editorial_forward transition publish', 'use editorial_forward transition revise']);
        $accountContext->set($editor);

        $entity = new ForwardDraftSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'draft', 'title' => 'Original title'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $repository->save($entity);
        $entityId = (string) $entity->id();
        $transitionService->transition($entity, 'publish', $editor);
        $publishedRevisionId = (int) $entity->get('revision_id');

        // Raw forward draft, deliberately WITHOUT setNewRevision(true): the
        // revisionDefault:false type would otherwise save in place.
        $tip = $repository->find($entityId);
        $this->assertNotNull($tip);
        $tip->set('title', 'Draft title');
        $tip->set('workflow_state', 'draft');
        $repository->save($tip);

        $draftRevisionId = (int) $tip->get('revision_id');
        $this->assertNotSame($publishedRevisionId, $draftRevisionId, 'A forward draft must create a NEW revision even on a new_revision:false bundle.');

        // The published revision row is untouched — content AND state.
        $publishedRow = $repository->loadRevision($entityId, $publishedRevisionId);
        $this->assertNotNull($publishedRow);
        $this->assertSame('Original title', $publishedRow->get('title'));
        $this->assertSame('published', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($publishedRow));

        // Pointer + status unchanged; the new tip carries the draft.
        $pointer = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($pointer);
        $this->assertSame($publishedRevisionId, (int) $pointer->get('revision_id'));
        $this->assertSame(1, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($repository->find($entityId)));
        // CW-v1 option-1 (#1920 PR-2): find() serves the PUBLISHED base
        // row (byte-stable — still 'Original title') here; loadWorkingCopy()
        // is what serves the draft tip.
        $this->assertSame('Original title', $repository->find($entityId)?->get('title'));
        $this->assertSame('published', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($repository->find($entityId)));
        $freshTip = $repository->loadWorkingCopy($entityId);
        $this->assertNotNull($freshTip);
        $this->assertSame('Draft title', $freshTip->get('title'));
        $this->assertSame('draft', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($freshTip));

        // Opt-out preserved for NON-state-changing edits: an ordinary edit
        // of the draft tip (workflow_state unchanged) updates in place — no
        // new revision.
        $freshTip->set('title', 'Draft title, edited');
        $repository->save($freshTip);
        $this->assertSame($draftRevisionId, (int) $freshTip->get('revision_id'), 'A non-state-changing edit on a new_revision:false bundle must still update in place.');
    }

    #[Test]
    public function raw_save_into_archived_on_an_opt_out_bundle_creates_an_unpromoted_tip_instead_of_corrupting_in_place(): void
    {
        // CW-v1 WP-2 task 2.6 verifier residual (#1920): the forced-revision
        // rule must be UNIFORM over all state-changing saves on pointered
        // entities — scoping it to defaultRevision:false targets left a raw
        // save into 'archived' (a defaultRevision:true state, legal via the
        // 'archive' edge) updating the pointered opt-out row IN PLACE while
        // applyState stamped the pointer-derived status (still published →
        // 1): one committed row with workflow_state='archived' AND
        // status=1. Raw saves NEVER enact pointer moves — the save creates
        // an unpromoted tip carrying 'archived' while the pointer and the
        // pointer-derived status stay truthful; enacting
        // defaultRevision:true states is exclusively TransitionService's
        // job.
        [$entityTypeManager, $provider, $accountContext] = $this->bootWiredProvider();
        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);
        $transitionService = $provider->resolve(TransitionService::class);

        $editor = $this->account(7, ['use editorial_forward transition publish', 'use editorial_forward transition archive']);
        $accountContext->set($editor);

        $entity = new ForwardDraftSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'draft', 'title' => 'Live title'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $repository->save($entity);
        $entityId = (string) $entity->id();
        $transitionService->transition($entity, 'publish', $editor);
        $publishedRevisionId = (int) $entity->get('revision_id');

        // Raw save into 'archived' (legal edge, permission held), NO
        // setNewRevision call, on the revisionDefault:false type.
        $tip = $repository->find($entityId);
        $this->assertNotNull($tip);
        $tip->set('workflow_state', 'archived');
        $repository->save($tip);

        $archivedTipRevisionId = (int) $tip->get('revision_id');
        $this->assertNotSame($publishedRevisionId, $archivedTipRevisionId, 'A state-changing raw save on a pointered opt-out row must create a NEW revision.');

        // The published revision row is byte-identical — content AND state.
        $publishedRow = $repository->loadRevision($entityId, $publishedRevisionId);
        $this->assertNotNull($publishedRow);
        $this->assertSame('Live title', $publishedRow->get('title'));
        $this->assertSame('published', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($publishedRow));

        // Pointer unchanged; base status still 1 (rides the pointer, which
        // still serves the published revision); the archived state lives
        // only on the unpromoted tip.
        //
        // CW-v1 option-1 (#1920 PR-2): the raw save is now DISCIPLINED
        // (bound + published pointer) and revision-creating (the guard
        // always forces a new revision for state-changing saves) — the
        // base row is therefore BYTE-UNCHANGED (still 'published'/'Live
        // title'), not merely status/pointer-consistent as the pre-option-1
        // assertion below claimed. loadWorkingCopy() is what serves the
        // unpromoted archived tip.
        $pointer = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($pointer);
        $this->assertSame($publishedRevisionId, (int) $pointer->get('revision_id'));
        $freshBase = $repository->find($entityId);
        $this->assertNotNull($freshBase);
        $this->assertSame(1, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($freshBase));
        $this->assertSame('published', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($freshBase), 'The base row is byte-unchanged under discipline — the archived state lives only on the unpromoted tip.');
        $this->assertSame('Live title', $freshBase->get('title'));

        $unpromotedTip = $repository->loadWorkingCopy($entityId);
        $this->assertNotNull($unpromotedTip);
        $this->assertSame('archived', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($unpromotedTip));
        $this->assertSame((string) $archivedTipRevisionId, (string) $unpromotedTip->get('revision_id'));
    }

    #[Test]
    public function raw_save_into_archived_on_a_revisioning_bundle_behaves_identically(): void
    {
        // Parity with the opt-out test above: on an ordinary revisioning
        // bundle (revisionDefault: true) the same raw save into 'archived'
        // produces the same shape — unpromoted archived tip, pointer and
        // pointer-derived status untouched. Raw saves never enact pointer
        // moves on ANY bundle.
        [$entityTypeManager, $provider, $accountContext] = $this->bootWiredProvider();
        $repository = $entityTypeManager->getRepository(self::REVISIONING_ENTITY_TYPE_ID);
        $transitionService = $provider->resolve(TransitionService::class);

        $editor = $this->account(7, ['use editorial_forward transition publish', 'use editorial_forward transition archive']);
        $accountContext->set($editor);

        $entity = new ForwardDraftSubject(
            ['bundle' => self::REVISIONING_ENTITY_TYPE_ID, 'workflow_state' => 'draft', 'title' => 'Live title'],
            self::REVISIONING_ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $repository->save($entity);
        $entityId = (string) $entity->id();
        $transitionService->transition($entity, 'publish', $editor);
        $publishedRevisionId = (int) $entity->get('revision_id');

        $tip = $repository->find($entityId);
        $this->assertNotNull($tip);
        $tip->set('workflow_state', 'archived');
        $repository->save($tip);

        $this->assertNotSame($publishedRevisionId, (int) $tip->get('revision_id'));

        $publishedRow = $repository->loadRevision($entityId, $publishedRevisionId);
        $this->assertNotNull($publishedRow);
        $this->assertSame('Live title', $publishedRow->get('title'));
        $this->assertSame('published', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($publishedRow));

        $pointer = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($pointer);
        $this->assertSame($publishedRevisionId, (int) $pointer->get('revision_id'));

        // CW-v1 option-1 (#1920 PR-2): base row byte-unchanged under
        // discipline (same as the opt-out parity test above) —
        // loadWorkingCopy() serves the unpromoted archived tip.
        $freshBase = $repository->find($entityId);
        $this->assertNotNull($freshBase);
        $this->assertSame(1, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($freshBase));
        $this->assertSame('published', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($freshBase));
        $this->assertSame('Live title', $freshBase->get('title'));

        $unpromotedTip = $repository->loadWorkingCopy($entityId);
        $this->assertNotNull($unpromotedTip);
        $this->assertSame('archived', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($unpromotedTip));
    }

    /**
     * @return array<string, string>
     */
    private function entityKeys(): array
    {
        return ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'];
    }

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
     * @return array{0: EntityTypeManager, 1: WorkflowServiceProvider, 2: RequestAccountContext}
     */
    private function bootWiredProvider(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            self::ENTITY_TYPE_ID . '.' . self::ENTITY_TYPE_ID => 'editorial_forward',
            self::REVISIONING_ENTITY_TYPE_ID . '.' . self::REVISIONING_ENTITY_TYPE_ID => 'editorial_forward',
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

        // revisionDefault stays false (the EntityType default) — this type
        // models a `new_revision: false` opt-out bundle for raw saves.
        $entityTypeManager->registerEntityType(new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'Forward draft subject',
            class: ForwardDraftSubject::class,
            keys: $this->entityKeys(),
            revisionable: true,
        ));

        // The parity type: an ordinary revisioning bundle (revisionDefault
        // true — every save creates a revision unless overridden).
        $entityTypeManager->registerEntityType(new EntityType(
            id: self::REVISIONING_ENTITY_TYPE_ID,
            label: 'Forward draft revisioning subject',
            class: ForwardDraftSubject::class,
            keys: $this->entityKeys(),
            revisionable: true,
            revisionDefault: true,
        ));

        // Test-local workflow (WP-2 rework, #1920): the shipped `editorial`
        // workflow no longer ships a `revise` (published -> draft) edge, so
        // this test persists its OWN workflow — the descoped shipped shape
        // plus a `revise` transition — and binds both fixture entity types
        // to it above, rather than to `editorial`.
        $entityTypeManager->getRepository('workflow')->save($this->editorialForwardWorkflow());

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

        // The subject under test: the REAL provider, booted against the REAL
        // kernel-services bus. boot() wires BOTH WorkflowStateGuard (task
        // 2.6's forward-draft status rule) and WorkflowPointerMoveGuard
        // (task 2.5/2.6's pointer-move validation) onto $dispatcher — the
        // SAME instance the repositoryFactory above dispatches through —
        // and seeds the SHIPPED `editorial` workflow (DefaultWorkflows::
        // EDITORIAL — no `revise` edge; the test-local `editorial_forward`
        // workflow persisted above is what the fixture types are actually
        // bound to).
        $provider = new WorkflowServiceProvider();
        $provider->setKernelServices($kernelServices);
        $provider->register();
        $provider->boot();

        return [$entityTypeManager, $provider, $accountContext];
    }

    /**
     * Builds the test-local `editorial_forward` workflow: the exact
     * descoped `DefaultWorkflows::EDITORIAL` shape, plus a `revise`
     * (published -> draft) transition the shipped workflow deliberately no
     * longer carries (WP-2 rework). Every transition's permission is
     * spelled out explicitly, re-derived for THIS workflow's id — {@see
     * Workflow::permissionFor()} only falls back to a derived
     * `use {workflow_id} transition {transition_id}` name when a
     * transition's own `permission` is empty, and the seed data (mirrored
     * here) always sets it explicitly.
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

final class ForwardDraftSubject extends ContentEntityBase implements RevisionableInterface, RevisionableEntityInterface
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
