<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Listener\RollbackAuditListener;
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
use Waaseyaa\Node\NodeServiceProvider;
use Waaseyaa\Node\NodeType;
use Waaseyaa\Workflows\DefaultWorkflows;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * The CW-v1 option-1 integration spine (#1920 PR-2, design §11 "PR sequence
 * and test spine" — PR-1's oracle, landed here where the engine makes it
 * real): the full editorial lifecycle on the REAL `Node` entity type — not a
 * synthetic fixture — bound to a test-local `editorial_forward` workflow
 * (the descoped shipped `EDITORIAL` shape plus a `revise` published -> draft
 * transition, persisted directly by this test), over real SQLite, through
 * the REAL {@see NodeServiceProvider} and {@see WorkflowServiceProvider}
 * wiring (`NodeRevisionDefaultListener`, both workflow guards, and the new
 * {@see \Waaseyaa\Workflows\Listener\WorkflowRepublishListener} all live on
 * the same dispatcher the repositories save through). The shipped
 * `editorial` workflow still does not ship a `revise` edge (PR-5 territory)
 * — this spine proves the ENGINE mechanics, not that the shipped workflow
 * exposes forward drafts yet.
 *
 * **Doctrine inversion, pinned here (design §1/§3.1):** under default-revision
 * discipline the base row holds the PUBLISHED revision, not the tip.
 * `find()` is BYTE-STABLE (the full raw base row, read straight off the
 * `node` table, not merely a few spot-checked fields) through the entire
 * draft window — a forward-draft edit, however many saves it takes, must
 * not move a single column of the served row. `loadWorkingCopy()` is the
 * pointer-aware alternative that serves the draft. This directly INVERTS
 * the pre-option-1 assertions this file used to carry ("base row carries
 * the tip") — that inversion is the entire point of the rebuild, not an
 * accident of refactoring.
 *
 * Story: create (guard forces draft, unpublished) -> publish (pointer +
 * status=1, base row = published content) -> forward-draft edit via
 * `editorial_forward`'s `revise` edge (find() byte-stable throughout;
 * loadWorkingCopy() serves the draft) -> submit for review (still
 * byte-stable) -> publish the draft revision (promotion: base row now the
 * new content, both pointers advanced; raw-SQL assertion pins the
 * promote-branch first save as revision-only) -> a plain same-state content
 * raw save by an any-of-authorized account (no TransitionService call) ->
 * auto-republish through the setPublishedRevision() choke point -> the same
 * shape by an UNAUTHORIZED account -> denied at PRE_SAVE, nothing committed
 * -> archive (pointer moves, status=0) -> rollback under discipline
 * (revision-only: a new draft tip carries the old content; the base row —
 * still archived — is byte-UNCHANGED, not "restored") -> revert
 * (`setCurrentRevision`) denied outright now that a live published pointer
 * exists. Full audit-trail assertion across TransitionService's own
 * `WorkflowTransition` records and the rollback's `RevisionRollback`
 * record.
 *
 * A storage-level `find()`/raw-SQL oracle is used here (per design §11's
 * fallback: "otherwise a storage-level find() oracle suffices") rather than
 * an anonymous JSON:API GET — wiring a full JsonApiController/
 * ResourceSerializer/EntityAccessHandler/routing stack into this
 * engine-focused spine is out of scope for PR-2; the HTTP-level byte-
 * stability oracle is PR-3's (design §4/§11) job, once the JSON:API surface
 * itself becomes pointer-aware.
 */
#[CoversNothing]
final class ForwardDraftFlowTest extends TestCase
{
    #[Test]
    public function full_editorial_lifecycle_under_default_revision_discipline_on_a_real_node_through_a_test_local_forward_draft_workflow(): void
    {
        [$entityTypeManager, $provider, $accountContext, $auditWriter, $db] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');
        $transitionService = $provider->resolve(TransitionService::class);

        $editor = $this->account(11, [
            'use editorial_forward transition publish',
            'use editorial_forward transition revise',
            'use editorial_forward transition submit_for_review',
            'use editorial_forward transition archive',
            'use editorial_forward transition restore_to_published',
        ]);
        // Any-of-authorized for a same-state 'published' republish (holds
        // 'publish', a transition INTO 'published') but with no relevant
        // edge/permission for the archived-pointer rollback later.
        $sameStateAuthorized = $this->account(13, ['use editorial_forward transition publish']);
        // Genuinely unauthorized for a same-state 'published' republish:
        // holds no transition whose target is 'published'.
        $sameStateUnauthorized = $this->account(14, ['use editorial_forward transition archive']);
        $accountContext->set($editor);

        // --- 1. Create: the save-path guard forces initial_state + ---
        // unpublished, regardless of Node's own constructor default
        // (status defaults to 1 when not explicitly given).
        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();

        $created = $nodeRepository->find($entityId);
        $this->assertNotNull($created);
        $this->assertSame('draft', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($created));
        $this->assertSame(0, (int) \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($created));
        $this->assertNull($nodeRepository->loadPublishedRevision($entityId), 'No published pointer exists before the first publish.');

        // --- 2. Publish: pointer established, status flips to 1. ---
        $result = $transitionService->transition($created, 'publish', $editor);
        $this->assertSame('published', $result->toState);

        $firstPublished = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($firstPublished);
        $firstPublishedRevisionId = (int) $firstPublished->get('revision_id');
        $this->assertSame('Original title', $firstPublished->get('title'));
        $this->assertSame(1, (int) \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($firstPublished));
        $this->assertSame(1, (int) \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($nodeRepository->find($entityId)));

        // --- 3. Forward-draft edit via `editorial_forward`'s 'revise' edge ---
        // (published -> draft): under discipline this is a DISCIPLINED,
        // state-changing, revision-creating save — WorkflowStateGuard forces
        // a new revision and sets the discipline flag, EntityRepository::doSave()
        // writes the revision row ONLY. The base row must be BYTE-IDENTICAL
        // before and after (design's headline byte-stability oracle).
        $baseRowBeforeRevise = $this->rawBaseRow($db, $entityId);

        $tip = $nodeRepository->find($entityId);
        $this->assertNotNull($tip);
        \assert($tip instanceof Node);
        $tip->setTitle('Forward draft title');
        $reviseResult = $transitionService->transition($tip, 'revise', $editor);
        $this->assertSame('draft', $reviseResult->toState);

        $baseRowAfterRevise = $this->rawBaseRow($db, $entityId);
        $this->assertSame(
            $baseRowBeforeRevise,
            $baseRowAfterRevise,
            'The base row must be BYTE-IDENTICAL (every column, not spot-checked) across a disciplined forward-draft save.',
        );

        // find() serves the PUBLISHED title throughout the draft window;
        // loadWorkingCopy() serves the draft.
        $servedDuringDraft = $nodeRepository->find($entityId);
        $this->assertNotNull($servedDuringDraft);
        $this->assertSame('Original title', $servedDuringDraft->get('title'), 'find() must keep serving the published title during the draft window.');
        $this->assertSame('published', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($servedDuringDraft), 'find() must keep reporting the PUBLISHED state — the served row never saw the draft edit.');
        $this->assertSame(1, (int) \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($servedDuringDraft));

        $workingCopyDuringDraft = $nodeRepository->loadWorkingCopy($entityId);
        $this->assertNotNull($workingCopyDuringDraft);
        $this->assertSame('Forward draft title', $workingCopyDuringDraft->get('title'), 'loadWorkingCopy() must serve the draft title.');
        $this->assertSame('draft', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($workingCopyDuringDraft));
        $draftTipRevisionId = (int) $workingCopyDuringDraft->get('revision_id');
        $this->assertNotSame($firstPublishedRevisionId, $draftTipRevisionId, 'The forward draft is a NEW, distinct revision.');

        // --- 4. Submit for review: another disciplined forward-draft save; ---
        // find() stays byte-stable throughout.
        $draftTip = $nodeRepository->loadWorkingCopy($entityId);
        $this->assertNotNull($draftTip);
        $submitResult = $transitionService->transition($draftTip, 'submit_for_review', $editor);
        $this->assertSame('review', $submitResult->toState);

        $baseRowAfterSubmit = $this->rawBaseRow($db, $entityId);
        $this->assertSame($baseRowBeforeRevise, $baseRowAfterSubmit, 'The base row must still be byte-identical after the second disciplined draft save.');

        $servedDuringReview = $nodeRepository->find($entityId);
        $this->assertNotNull($servedDuringReview);
        $this->assertSame('Original title', $servedDuringReview->get('title'));
        $this->assertSame('published', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($servedDuringReview));
        $reviewTip = $nodeRepository->loadWorkingCopy($entityId);
        $this->assertNotNull($reviewTip);
        $this->assertSame('review', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($reviewTip));
        $this->assertSame($firstPublishedRevisionId, (int) $nodeRepository->loadPublishedRevision($entityId)?->get('revision_id'));

        // --- 5. Publish the draft revision: promotion. ---
        // Raw-SQL assertion (design §3.2: "verify with a raw-SQL assertion in
        // tests, not by re-implementing anything") that the promote branch's
        // FIRST save (revision-creating) does not touch the base row before
        // setPublishedRevision() runs: read the raw row mid-transaction is not
        // observable from outside, but the base row's PRE-promotion snapshot
        // (still 'Original title', captured above as $baseRowBeforeRevise) is
        // the oracle — if the first save wrote the base row directly (the
        // undisciplined bug this rebuild fixes), $baseRowBeforeRevise would
        // already have diverged well before this step ever runs. The
        // post-promotion snapshot below proves the SECOND phase (the
        // discipline-flagged setPublishedRevision() full-row copy) is what
        // actually moves the base row, not an incremental drift.
        $promoteResult = $transitionService->transition($reviewTip, 'publish', $editor);
        $this->assertSame('published', $promoteResult->toState);

        $promoted = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($promoted);
        $promotedRevisionId = (int) $promoted->get('revision_id');
        $this->assertNotSame($firstPublishedRevisionId, $promotedRevisionId, 'Promotion must move the pointer to a NEW revision.');
        // Every TransitionService call creates its own fresh tip revision
        // (Task 2.3) — 'publish' does not reuse $draftTipRevisionId's row,
        // it copies its CONTENT forward into a brand-new revision that then
        // gets promoted.
        $this->assertGreaterThan($draftTipRevisionId, $promotedRevisionId);
        $this->assertSame('Forward draft title', $promoted->get('title'));
        $this->assertSame(1, (int) \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($promoted));

        $baseRowAfterPromotion = $this->rawBaseRow($db, $entityId);
        $this->assertSame('Forward draft title', $baseRowAfterPromotion['title'], 'The base row now serves the promoted content — setPublishedRevision() copied it in full under discipline.');
        $this->assertSame((string) $promotedRevisionId, (string) $baseRowAfterPromotion['revision_id']);
        $this->assertSame((string) $promotedRevisionId, (string) $baseRowAfterPromotion['published_revision_id'], 'Under discipline both pointers land on the same revision — the base row cannot diverge from what it claims to serve.');
        $this->assertNotSame($baseRowBeforeRevise, $baseRowAfterPromotion, 'Promotion is the one moment in this story that the base row IS allowed — and required — to change.');

        // --- 6. A plain same-state content raw save by an any-of-authorized ---
        // account: no TransitionService call, no workflow_state change —
        // just a content edit, mirroring a generic client's PATCH. Under
        // discipline this forks a revision-only tip that WorkflowStateGuard's
        // same-state-republish gate authorizes and arms, and
        // WorkflowRepublishListener auto-promotes at POST_SAVE.
        $accountContext->set($sameStateAuthorized);
        $servedBeforeSameStateEdit = $nodeRepository->find($entityId);
        $this->assertNotNull($servedBeforeSameStateEdit);
        \assert($servedBeforeSameStateEdit instanceof Node);
        $servedBeforeSameStateEdit->setTitle('Same-state authorized edit');
        $nodeRepository->save($servedBeforeSameStateEdit);

        $republished = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($republished);
        $this->assertSame('Same-state authorized edit', $republished->get('title'), 'An authorized same-state edit must republish through the choke point.');
        $republishedRevisionId = (int) $republished->get('revision_id');
        $this->assertNotSame($promotedRevisionId, $republishedRevisionId, 'The republish creates and promotes exactly one new revision.');

        $servedAfterSameStateEdit = $nodeRepository->find($entityId);
        $this->assertNotNull($servedAfterSameStateEdit);
        $this->assertSame('Same-state authorized edit', $servedAfterSameStateEdit->get('title'), 'The base row is updated THROUGH the choke point, not left stale.');
        $this->assertSame((string) $republishedRevisionId, (string) $servedAfterSameStateEdit->get('revision_id'));
        $this->assertSame((string) $republishedRevisionId, (string) \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::publishedRevisionId($servedAfterSameStateEdit));

        // --- 7. The same shape by an UNAUTHORIZED account: denied at ---
        // PRE_SAVE, nothing committed (the base row stays exactly on the
        // step-6 republished revision).
        $accountContext->set($sameStateUnauthorized);
        $servedBeforeDeniedEdit = $nodeRepository->find($entityId);
        $this->assertNotNull($servedBeforeDeniedEdit);
        \assert($servedBeforeDeniedEdit instanceof Node);
        $servedBeforeDeniedEdit->setTitle('This edit must never land');

        $deniedSameState = null;
        try {
            $nodeRepository->save($servedBeforeDeniedEdit);
        } catch (TransitionDeniedException $e) {
            $deniedSameState = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $deniedSameState);
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $deniedSameState->reason);

        $servedAfterDeniedEdit = $nodeRepository->find($entityId);
        $this->assertNotNull($servedAfterDeniedEdit);
        $this->assertSame('Same-state authorized edit', $servedAfterDeniedEdit->get('title'), 'A denied same-state edit must leave the served content untouched.');
        $this->assertSame((string) $republishedRevisionId, (string) $servedAfterDeniedEdit->get('revision_id'));

        // --- 8. Archive: pointer moves again, status flips to 0. ---
        $accountContext->set($editor);
        $archiveSubject = $nodeRepository->find($entityId);
        $this->assertNotNull($archiveSubject);
        $archiveResult = $transitionService->transition($archiveSubject, 'archive', $editor);
        $this->assertSame('archived', $archiveResult->toState);

        $archived = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($archived);
        $archivedRevisionId = (int) $archived->get('revision_id');
        $this->assertSame('archived', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($archived));
        $this->assertSame('Same-state authorized edit', $archived->get('title'), 'Archiving does not change content, only state.');
        $this->assertSame(0, (int) \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($nodeRepository->find($entityId)));

        // --- 9a. Rollback attempt WITHOUT permission: denied via the ---
        // pointer guard, persisted state proven unchanged.
        $restrictedEditor = $this->account(12, [
            'use editorial_forward transition publish',
            'use editorial_forward transition archive',
        ]);
        $accountContext->set($restrictedEditor);

        $denied = null;
        try {
            $nodeRepository->rollback($entityId, $firstPublishedRevisionId);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $denied->reason);

        $afterDenial = $nodeRepository->find($entityId);
        $this->assertNotNull($afterDenial);
        $this->assertSame($archivedRevisionId, (int) $afterDenial->get('revision_id'));
        $this->assertSame('archived', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($afterDenial));
        $this->assertSame(0, (int) \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($afterDenial));

        $this->assertSame(
            0,
            $this->countRollbackRecords($auditWriter),
            'A denied rollback attempt must not produce a rollback audit record.',
        );

        // --- 9b. Rollback WITH permission, UNDER DISCIPLINE: revision-only. ---
        // This is the consciously INVERTED assertion (design §2.3): the
        // pre-option-1 version of this test asserted the base row was
        // IMMEDIATELY rewritten with the restored content. Under discipline
        // the restored content becomes a new DRAFT tip only — the base row
        // (still serving the archived content) is byte-UNCHANGED.
        $accountContext->set($editor);
        $baseRowBeforeRollback = $this->rawBaseRow($db, $entityId);

        $rolledBack = $nodeRepository->rollback($entityId, $firstPublishedRevisionId);

        $this->assertSame('Original title', $rolledBack->get('title'), 'The rollback return value carries the restored content.');
        $this->assertSame('published', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($rolledBack));
        $newDraftTipRevisionId = (int) $rolledBack->get('revision_id');
        $this->assertNotSame($archivedRevisionId, $newDraftTipRevisionId);
        $this->assertNotSame($firstPublishedRevisionId, $newDraftTipRevisionId, 'Rollback copies content FORWARD as a new revision — it never re-points at the old one.');

        $baseRowAfterRollback = $this->rawBaseRow($db, $entityId);
        $this->assertSame(
            $baseRowBeforeRollback,
            $baseRowAfterRollback,
            'Under discipline, rollback() is revision-only: the base row (still serving the archived pointer/content) must be BYTE-IDENTICAL — this inverts the pre-option-1 "base row carries the restored content" assertion by design.',
        );

        $servedAfterRollback = $nodeRepository->find($entityId);
        $this->assertNotNull($servedAfterRollback);
        $this->assertSame('Same-state authorized edit', $servedAfterRollback->get('title'), 'find() still serves the archived content — the base row never moved.');
        $this->assertSame('archived', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($servedAfterRollback));

        $newDraftTip = $nodeRepository->loadWorkingCopy($entityId);
        $this->assertNotNull($newDraftTip);
        $this->assertSame('Original title', $newDraftTip->get('title'), 'The restored content lives ONLY in the new working-copy tip.');
        $this->assertSame((string) $newDraftTipRevisionId, (string) $newDraftTip->get('revision_id'));

        $rollbackRecords = $this->rollbackRecords($auditWriter);
        $this->assertCount(1, $rollbackRecords, 'A successful rollback must produce exactly one rollback audit record.');
        $this->assertSame('allowed', $rollbackRecords[0]->outcome);
        $this->assertSame('node', $rollbackRecords[0]->entityTypeId);
        $this->assertSame($entityId, $rollbackRecords[0]->attributes['entity_id']);
        $this->assertSame($archivedRevisionId, $rollbackRecords[0]->attributes['from_revision_id']);

        // --- 10. Revert (setCurrentRevision) denied outright: a live ---
        // published pointer exists, so a direct base-row repoint has no
        // coherent meaning under discipline (design §2.3) — denied
        // regardless of account/permission, before the pre-option-1
        // same-state/different-state edge logic is ever consulted.
        $baseRowBeforeRevertAttempt = $this->rawBaseRow($db, $entityId);
        $revertDenied = null;
        try {
            $nodeRepository->setCurrentRevision($entityId, $newDraftTipRevisionId);
        } catch (TransitionDeniedException $e) {
            $revertDenied = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $revertDenied);
        $this->assertSame(TransitionDeniedException::REASON_ILLEGAL_EDGE, $revertDenied->reason);
        $this->assertStringContainsString('rollback', $revertDenied->getMessage());

        $baseRowAfterRevertAttempt = $this->rawBaseRow($db, $entityId);
        $this->assertSame($baseRowBeforeRevertAttempt, $baseRowAfterRevertAttempt, 'A denied revert must leave the base row completely untouched.');

        // --- Full audit-trail assertion: every TransitionService call in ---
        // this story (publish, revise, submit_for_review, publish/promotion,
        // archive = 5) recorded 'allowed'; no TransitionService call was
        // denied (every guard-level denial above happened OUTSIDE
        // TransitionService, so none of them touch this audit kind).
        $transitionRecords = array_values(array_filter(
            $auditWriter->recorded,
            static fn(AuditEventDescriptor $d): bool => $d->kind === AuditEventKind::WorkflowTransition,
        ));
        $this->assertCount(5, $transitionRecords, 'Exactly five TransitionService calls occurred in this story.');
        foreach ($transitionRecords as $record) {
            $this->assertSame('allowed', $record->outcome);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rawBaseRow(DBALDatabase $db, string $entityId): array
    {
        // Node's id column is `nid` (#[ContentEntityKeys(id: 'nid', ...)]),
        // not `id`.
        $row = $db->getConnection()->fetchAssociative('SELECT * FROM node WHERE nid = ?', [$entityId]);
        $this->assertIsArray($row, 'Base row must exist.');

        return $row;
    }

    /**
     * @return list<AuditEventDescriptor>
     */
    private function rollbackRecords(ForwardDraftFlowSpyAuditWriter $auditWriter): array
    {
        return array_values(array_filter(
            $auditWriter->recorded,
            static fn(AuditEventDescriptor $d): bool => $d->kind === AuditEventKind::RevisionRollback,
        ));
    }

    private function countRollbackRecords(ForwardDraftFlowSpyAuditWriter $auditWriter): int
    {
        return count($this->rollbackRecords($auditWriter));
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
     * Wires real dispatcher + real SQLite-backed EntityTypeManager (node,
     * node_type, workflow), then boots the REAL NodeServiceProvider and the
     * REAL WorkflowServiceProvider against a stub kernel-services bus that
     * serves the collaborators both providers need under the exact FQCNs
     * production code resolves them by. The SAME dispatcher instance is fed
     * to both providers' `addListener()`/`addSubscriber()` calls and the
     * EntityRepository instances that perform every save/pointer-move in
     * this test, so the real listeners fire, not stand-ins — including the
     * CW-v1 option-1 (#1920 PR-2) `WorkflowRepublishListener`, wired by the
     * same `WorkflowServiceProvider::boot()` call as the two pre-existing
     * guards.
     *
     * @return array{0: EntityTypeManager, 1: WorkflowServiceProvider, 2: RequestAccountContext, 3: ForwardDraftFlowSpyAuditWriter, 4: DBALDatabase}
     */
    private function bootWiredProviders(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            'node.article' => 'editorial_forward',
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
        $auditWriter = new ForwardDraftFlowSpyAuditWriter();

        $kernelServices = new class ($dispatcher, $entityTypeManager, $configFactory, $accountContext, $auditWriter) implements KernelServicesInterface {
            public function __construct(
                private readonly SymfonyEventDispatcherAdapter $dispatcher,
                private readonly EntityTypeManager $entityTypeManager,
                private readonly ConfigFactoryInterface $configFactory,
                private readonly AccountContextInterface $accountContext,
                private readonly AuditWriterInterface $auditWriter,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    EntityTypeManager::class, EntityTypeManagerInterface::class => $this->entityTypeManager,
                    ConfigFactoryInterface::class => $this->configFactory,
                    AccountContextInterface::class => $this->accountContext,
                    AuditWriterInterface::class => $this->auditWriter,
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

        // Test-local workflow (WP-2 rework, #1920): the shipped `editorial`
        // workflow no longer ships a `revise` (published -> draft) edge, so
        // this spine persists its OWN workflow — the descoped shipped shape
        // plus a `revise` transition — and binds `node.article` to it above,
        // rather than to `editorial`. This keeps the engine coverage (the
        // forward-draft branch in TransitionService/WorkflowStateGuard is
        // dormant substrate, still reachable via a custom workflow) without
        // pretending the shipped workflow exposes the edge.
        $entityTypeManager->getRepository('workflow')->save($this->editorialForwardWorkflow());

        // Wires NodeRevisionDefaultListener (Task 2.3) onto PRE_SAVE.
        $nodeProvider->boot();
        // Wires WorkflowStateGuard + WorkflowPointerMoveGuard +
        // WorkflowRepublishListener (CW-v1 option-1, #1920 PR-2) onto the
        // same dispatcher, and seeds the shipped `editorial` workflow (which
        // no longer carries a `revise` edge — see above).
        $workflowProvider->boot();

        // Rollback audit coverage (Task 2.5): wired directly with the real
        // listener class rather than via the full AuditServiceProvider
        // (which needs a real audit DB/schema this spine does not otherwise
        // exercise) — same production class, real dispatch, a spy writer
        // standing in for the DB-backed AuditEventWriter.
        $dispatcher->addSubscriber(new RollbackAuditListener($auditWriter, null, $accountContext));

        // A NodeType row for the bound bundle, matching realistic production
        // shape (Task 2.3's per-bundle new_revision knob; the entity-type
        // default already forces revisioning regardless of this row).
        $entityTypeManager->getRepository('node_type')->save(new NodeType(['type' => 'article', 'name' => 'Article']));

        return [$entityTypeManager, $workflowProvider, $accountContext, $auditWriter, $db];
    }

    /**
     * Builds the test-local `editorial_forward` workflow: the exact
     * descoped `DefaultWorkflows::EDITORIAL` shape (states + the
     * submit_for_review/publish/reject/archive/restore/restore_to_published
     * transitions), plus a `revise` (published -> draft) transition the
     * shipped workflow deliberately no longer carries (WP-2 rework). Every
     * transition's permission is spelled out explicitly, re-derived for THIS
     * workflow's id — {@see Workflow::permissionFor()} only falls back to a
     * derived `use {workflow_id} transition {transition_id}` name when a
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

final class ForwardDraftFlowSpyAuditWriter implements AuditWriterInterface
{
    /** @var list<AuditEventDescriptor> */
    public array $recorded = [];

    public function record(AuditEventDescriptor $descriptor): void
    {
        $this->recorded[] = $descriptor;
    }
}
