# FW-MESSAGING-ATOMIC-CREATE-01

Status: local implementation candidate. Existing issue: #2753; convergence program: #3118.
Base: `2ba3821a8224950b443fc5060e5541fb29e4962a`.

## Scope and decisions

Consume the messaging convergence assessment retained in the local audit draft.
This slice owns atomic creator membership, not a chat feature or the remaining
membership, attribution, retention, API identity or private authority findings.

The canonical repository save remains creation authority for generic API,
tools and trusted repository callers. A transactional `EntityPersistedEvent`
is dispatched through the existing Symfony dispatcher after writes/hooks and
identity assignment, before commit. It is a required-invariant hook, never a
post-commit notification. Failure aborts the surrounding single/batch transaction.
Entity storage owns this extension seam; messaging owns creator membership.
No alternative event bus, transaction manager or application service is added.

D1 for this slice: an authenticated creation must persist exactly one owner
for the acting account, independent of submitted created_by. Actorless trusted
CLI/system creation remains supported with no automatic membership. The supported
composition is default sql-blob on SQLite, with thread and participant repositories
sharing the transaction connection. Canonical provider boot refuses memory-only
repositories before writes; it does not silently model unsupported parity.
After a failed mutation, discard its in-memory entities and retry with fresh
objects; rollback does not rewind entity hooks or assigned identities.
Repeated saves of a successful thread are updates; the existing composite unique
key fences duplicate membership. No cross-request idempotency key is introduced.

## Ownership and verification

Integration owner: Codex root. Owned files: messaging subscriber/provider/tests,
entity-storage event/repository and affected storage tests, enduring messaging
and entity-system specs, this record and release fragment. No parallel implementation.
The scoped lease coordinator refuses native Windows drive paths before mutation;
the isolated worktree `C:/dev/waaseyaa/messaging-atomic-create`, branch
`fix/messaging-atomic-create`, is retained for custody instead. No main edits.

Test-first evidence: canonical-provider single and batch insertion-failure tests
fail against the base because creation reports success. Real SQLite triggers
provide the failure; accounts/service lookup are synthetic. Positive creation,
actorless creation, mixed batches, repeated saves, uniqueness, outer rollback,
commit-only notifications and unsupported composition refusal discriminate the fix.
Run messaging and affected storage transaction suites, then default governed
preflight. One immutable-candidate subagent review owns independent review.
Hosted `ci/full-qualification` owns broad native-unsupported qualification;
split installation qualification remains a separate convergence slice.

No GitHub publication, merge, release or deployment is authorized by this local
implementation. Remaining audit findings retain their existing dispositions.

## Candidate evidence

- Test-first failure: canonical-provider single and batch creation tests both
  failed against the unchanged base because membership failure reported success.
- Focused combined result: 70 tests / 320 assertions, PHP 8.5.5 native Windows,
  exit 0, 0.761 seconds. Source integration evidence, not installed qualification.
- Changed storage and messaging classes resolve into the owned worktree through
  candidate-local Composer paths; no donor vendor or autoload overrides were used.
- Pinned Deptrac 4.7.2 diagnostic refresh: 140 allowed edges, zero violations,
  zero uncovered; a Mermaid dependency view is retained in the local repair
  evidence directory. The necessary messaging-to-storage invariant hook is an
  intentional L3-to-L1 dependency, not a new architecture authority under #3075.
- Public-surface views and test-only SQLite construction/schema authority rosters
  are refreshed from their governed generators. Test-only trigger changes do not
  introduce a production schema bypass.
- Immutable review and final default preflight evidence are retained with the
  candidate outside the repository. Full hosted qualification remains pending.
