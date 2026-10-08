# FW-MAINTAINER-SKILL-CUSTODY-01

## Scope and authority

Russell requested repository-owned Waaseyaa skills and cleanup of computer-only
copies on 2026-10-08. This change recovers the human-navigation and PHPDoc/comment
updates into the canonical skills and removes the four global Codex/Claude skill
copies from discovery on the maintainer host. No push, merge or release is claimed.

Candidate: `codex/maintainer-skill-custody`, base
`f2b2da6dd58092d611ad52f5a2bf0fb95712ffbc`. Sole implementation owner: Codex root.
Native Windows Git: `C:/Program Files/Git/cmd/git.exe`. Isolated retained worktree
has explicit custody in this record; no coordinator lease is claimed.

## Decision

Framework `.agents/skills/` remains the sole authoring authority. Normal work
loads this repository source directly. Clients running outside Framework locate
its checkout and read the appropriate skill there. Guidance contains no fixed
maintainer-computer checkout path. Global export remains available through the
existing installer only when explicitly requested; it is no longer routine
setup or refresh guidance. Installer behavior and its safety controls are unchanged.

Retain separate convergence and delivery skills: assessment and implementation
are different workflows. Keep maintainability criteria in the audit checklist;
delivery routes to it without duplicating the checklist or restarting an audit.
Add explicit SoC/SRP, DRY, file navigation and PHPDoc/comment accuracy to the
checklist, assessment requirements and record template. Existing audits need a
bounded supplement for missing answers, not rewritten historical evidence.

## Recovery and cleanup

Compared every file in the four installed copies with the repository source.
The Codex differences were exactly this session's four edited files; these were
recovered into the candidate with LF line endings. Claude differences only
added obsolete harness-specific `CLAUDE.md` routing, which conflicts with the
current single AGENTS entrypoint and was not imported.

The two skill directories under each of the Codex and Claude global user skill
roots were moved to a recoverable scratch location outside skill discovery.
Resolved absolute source and destination paths were checked before each move;
linked directories were refused. No unrelated skill was touched. Post-cleanup
inventory found zero Waaseyaa skill directories in Codex, Claude and Agents user
skill roots. Recovery backups are not an authoring authority. Already-running
sessions can retain the old catalog; new sessions must use repository discovery
or explicitly load the repository files.

## Acceptance and evidence

- Repository skill validator passes both skills, including relative resources.
- Focused maintainer-skill architecture tests cover validation, source authority,
  install/verify provenance and refusal behavior; no new runtime code is added.
- Default preflight and independent immutable-delta review qualify this candidate.
- Local cleanup has no active global Waaseyaa skill copies; repository changes
  are versioned separately from historical listing audit work.

## Final local evidence, 2026-10-08

- `php bin/maintainer-skills validate`: both repository skills valid.
- Candidate-local `MaintainerSkillsTest`: 25 tests, 339 assertions pass.
- Default preflight: 42 gates executed, zero failures, four not applicable.
  This is native default-profile evidence, not full hosted qualification.
- Independent immutable-delta review: approve, no blocking findings. All eight
  manifest paths and complete diff SHA-256
  `8fd5fa35675ac965dbfca8e8f372b5bc6eca0c3e18fa0c1395493062e589d4a0`
  were verified; protected-checkout integrity passed after review.
- The non-interactive preflight attempt stalled before reporting gate results;
  its exact process identity was checked and stopped. The same canonical command
  completed in the interactive terminal in 162.2 seconds. No source or permission
  workaround was introduced, and the stopped attempt supplies no passing evidence.
- Global user-skill inventory by name and skill frontmatter found no remaining
  Waaseyaa discovery entries. Other client discovery modes were not exercised;
  explicit repository-file loading is the documented fallback.

Only this execution appendix was added after the reviewed candidate and completed
preflight; the reviewed skill and routing bytes are unchanged. Main and the listing
repair checkout remain clean. Hosted qualification, sharing the repository commit
and governed landing remain separate from this local checkpoint.
