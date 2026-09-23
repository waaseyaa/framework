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

- **States:** `current`, `stale`, `drifted`, `missing`, `unmanaged`,
  `invalid-manifest`. `verify` exits 0 only when every copy is `current`.
- **Refusal before writing:** `install` plans every target first and writes
  nothing if any target is drifted, unmanaged (unless `--adopt` and
  content-equal apart from CRLF), has an untrustworthy manifest, or the source
  has uncommitted changes (unless `--allow-dirty-source`).
- **Manifest trust:** a manifest must name the expected skill and canonical
  source, a full commit id, a boolean clean flag, and only safe relative paths
  with SHA-256 digests. Otherwise the directory is `invalid-manifest`, so a
  tampered manifest can never direct deletions outside the skill.
- **Fail closed:** every delete, directory creation, write and rename is
  checked. Files and the manifest are written through a temporary file and a
  rename. A failed first install removes exactly what it created. A failed
  update keeps the old manifest, so the copy verifies as drifted. Any failure
  exits 1 and reports how many directories completed; success is never
  printed after a failure.
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
| Repair candidate | `RecursiveRemoverContractTest` allowlist check | Found set equals the allowlist; locally it still fails on a Windows path-prefix mismatch and three symlink controls, all owned by hosted Linux |
| Install on the maintainer's host | `install --adopt`, then `verify` | Existing Codex copies adopted unchanged; Claude Code copies installed; all four `current` |
| Discovery, Claude Code | A running session whose working directory is outside Framework | Both skills listed after install |
| Discovery, Codex | `codex debug prompt-input` (no model call) from `waaseyaa/studio` | Both skills listed from the user skill directory |

## Residuals

- After merge, reinstall from `main` so installed manifests point to a commit
  on `main`. Copies installed from a PR branch are previews.
- Restructuring the convergence method is separate (PR #3133, #3118).
