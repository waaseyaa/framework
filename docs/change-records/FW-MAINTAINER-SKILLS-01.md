# FW-MAINTAINER-SKILLS-01 — versioned maintainer skills

- Forge mirror: `waaseyaa/framework#3080`; follow-on restructure under #3118
- Base: `fa276b52a4785dfe40d16acd7c5197054592b4a0`
- Branch: `claude/maintainer-skills-3080`, PR #3132
- Related: ADR-026 (client skill conventions), #3096 and #3081 (Windows host limits)
- Authority: repository source, installer, tests and PR. No release,
  publication or deployment. Landing needs the maintainer's approval.

## Problem

The Waaseyaa maintainer skills (`waaseyaa-delivery`,
`waaseyaa-package-convergence`) existed only in one machine's Codex skills
directory. They were unreviewed, unversioned, changed three times in four days,
and invisible to Claude Code. Package audits ran on different versions of the
method.

## Decision

- `.agents/skills/<name>/` in Framework is the only authority. Codex discovers
  it inside the Framework checkout (ADR-026 cites the discovery rule).
- `php bin/maintainer-skills install` copies each skill into the Codex and
  Claude Code user skill directories for use in every other repository.
  Installed copies are generated artifacts with a provenance manifest.
- The skills are maintainer tooling, not consumer guidance. They stay outside
  `packages/bimaaji/resources/skills` and are excluded from the published
  archive (`/.agents/ export-ignore`, already present).

## Installer contract

- **States:** `current`, `provenance-stale`, `stale`, `drifted`, `missing`,
  `unmanaged`, `invalid-manifest`. `current` requires identical bytes AND the
  same source commit and clean flag. `verify` exits 0 only when every copy is
  `current`.
- **Provenance refresh:** identical bytes from another commit (for example a
  squash merge) are `provenance-stale`; `install` rewrites only the manifest
  and reports `refreshed`, leaving the skill files untouched.
- **Source bound to one commit:** when the source is clean, `verify` and
  `install` read skill bytes from Git objects at the recorded commit, never
  the working tree, and fail if HEAD or cleanliness changes while reading. A
  dirty source (only with `--allow-dirty-source`) is read from the working
  tree and recorded as `source_clean: false`. `validate` checks the working
  tree so authors can validate before committing.
- **Strict source reads:** a source file that can't be read stops `validate`,
  `verify` and `install` before anything is written.
- **Custody:** each target is fingerprinted (every path, type, digest and
  link target, including the manifest) during planning and rechecked
  immediately before its first mutation. A changed target is refused and
  nothing is written to it. A small window remains between that check and
  the first write; there is no cross-process lock.
- **Refusal before writing:** `install` plans every target first and writes
  nothing if any target is drifted, unmanaged (unless `--adopt` and
  content-equal apart from CRLF), has an untrustworthy manifest, or the source
  has uncommitted changes (unless `--allow-dirty-source`).
- **Manifest trust:** a manifest must name the expected skill and canonical
  source, a full commit id, a boolean clean flag, and only safe relative paths
  with SHA-256 digests. Otherwise the directory is `invalid-manifest`, so a
  tampered manifest can never direct deletions outside the skill.
- **Fail closed:** every delete (including temporary-file cleanup), directory
  creation, write and rename is checked, and any `Throwable` is handled. A
  temporary file that can't be removed is named in the failure. Files and the manifest are written
  through a temporary file and a rename. A failed first install removes what
  it created and checks each removal; the report says `Rolled back` only when
  nothing is left, otherwise `ROLLBACK INCOMPLETE` with the leftover paths. A
  failed update or adoption is not rolled back. After any failure the command
  re-inspects the directory and prints its actual state (and, if unmanaged,
  whether it is still adoptable); if that inspection itself fails, the
  original failure is still reported with the state as `unknown`. It exits 1
  and never prints success.
- **Validation** is deliberately narrower than skill-creator's
  `quick_validate.py`: flat single-line frontmatter scalars only, allowed keys
  `name`, `description`, `license`, `allowed-tools`; no `TODO` markers, CR line
  endings, or unresolved relative links.

## Evidence

| Candidate | Evidence | Result |
| --- | --- | --- |
| `910c4b687` | Hosted run 35896172412 | 7 failures, one root: `RecursiveRemoverContractTest` rejected the test's hand-written recursive remover. The other six were aggregates. |
| Repair candidate | `tests/Architecture/MaintainerSkillsTest.php`, native Windows, PHP 8.5.5 | 13 tests, 198 assertions pass |
| Repair candidate | Mutations: accept unsafe paths, skip source check, drop rollback, continue after failure, allow `TODO` | Each fails at least one test |
| Repair candidate | Mutation: non-atomic manifest write | Not detected; atomicity is only observable under a crash. Accepted residual. |
| `9dad52d3b` | Hosted checks | All 56 pass; Codex review then found three blockers (source reads, provenance, rollback truthfulness) |
| Second repair (Codex review of `9dad52d3b`) | 17 tests, 249 assertions; mutations: lenient source read, ignored provenance, unchecked rollback, assumed post-failure state, refresh rewriting files, catching only `RuntimeException` | Each mutation fails at least one test |
| `18849fa8e` | Hosted checks | All 56 pass; Codex review then found: bytes not bound to the commit, no custody recheck before mutation, failure report lost if reinspection fails, unchecked temp cleanup |
| Third repair | 21 tests, 288 assertions; mutations: read the working tree, skip the custody check, unguarded reinspection, unchecked temp cleanup | Each mutation fails at least one test |
| Repair candidate | `RecursiveRemoverContractTest` allowlist check | Found set equals the allowlist; locally it still fails on a Windows path-prefix mismatch and three symlink controls, all owned by hosted Linux |
| Install on the maintainer's host | `install --adopt`, then `verify` | Existing Codex copies adopted unchanged; Claude Code copies installed; all four `current` |
| Discovery, Claude Code | A running session whose working directory is outside Framework | Both skills listed after install |
| Discovery, Codex | `codex debug prompt-input` (no model call) from `waaseyaa/studio` | Both skills listed from the user skill directory |

## Residuals

- After merge, run `install` from `main`: identical bytes are `refreshed`, so
  every manifest then points at a commit on `main`. Copies installed from a PR
  branch are previews until then.
- Fault injection (`WAASEYAA_MAINTAINER_SKILLS_TEST_FAULT`) is a documented
  test-only seam in the maintainer tool.
- Restructuring the convergence method is separate (PR #3133, #3118).
