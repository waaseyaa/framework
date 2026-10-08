# Maintainer skills

These are the Waaseyaa **maintainer** skills: workflows for people and agents
developing Waaseyaa itself, used across Framework, Studio, Cloud, and other
Waaseyaa repositories.

| Skill | Purpose |
| --- | --- |
| `waaseyaa-package-convergence` | Audit one package and turn the findings into a governed cleanup plan |
| `waaseyaa-delivery` | Run authorized implementation, review, CI repair, and landing work |

This directory is the only authority for them. They are **not** the consumer
skills that `bimaaji:install` ships to applications; those live in
`packages/bimaaji/resources/skills/`. This directory is excluded from the
published `waaseyaa/framework` archive.

## Changing a skill

1. Edit the files here in an isolated reviewed batch. A pull request is optional
   under repository governance. `tests/Architecture/MaintainerSkillsTest.php`
   and `php bin/maintainer-skills validate` check the structure.
2. Commit the change in this repository. Share and land it through the authorized
   repository workflow so every maintainer uses the same version.
3. Load the repository source directly. Do not edit or routinely install global
   client copies; they can shadow the source and diverge between computers.

## Repository discovery

Codex discovers this directory when its working directory is inside Framework.
Outside Framework, or in a client without repository skill discovery, locate
an available Framework checkout and read the relevant `SKILL.md` and referenced
resources there. Use repository-relative paths in maintained guidance, never a
particular computer's checkout path. If no checkout is available, obtain the
repository through the normal authorized setup rather than inventing a local
variant. A session started above Framework may need to enter the checkout or
explicitly load these files; an already-loaded session may retain old guidance
until a new session.

Before removing an old global copy, compare every file with the source and
recover useful local edits into a repository change. Then remove the specific
obsolete skill directories from client discovery, preserving unrelated skills.
Do not use the installer to overwrite drift before recovering it.

## Optional client export

`php bin/maintainer-skills install` remains available only for an explicitly
requested client export. It is not part of the normal edit or setup workflow.
Without `--target`, it exports to `$CODEX_HOME/skills` (default
`~/.codex/skills`) and `$CLAUDE_CONFIG_DIR/skills` (default `~/.claude/skills`).
Use `--target=DIR` (repeatable) to choose another directory, then `verify` with
the same targets. These are generated copies, never authoring locations.

Each installed skill gets a `.waaseyaa-skill.json` manifest with the source
commit and a SHA-256 for every file. `verify` reports each copy as `current` (same bytes and same source commit),
`provenance-stale` (same bytes, but installed from a different commit, for
example before a squash merge), `stale` (the source changed), `drifted` (someone edited the copy), `missing`,
`unmanaged` (a directory the installer did not create), or `invalid-manifest`
(the manifest names the wrong skill or source, or lists unsafe paths or
malformed digests).

`install` decides every target before writing anything, and it refuses rather
than overwriting:

- a copy with an **invalid manifest**. Delete it and install again;
- a **drifted** copy. Move the local edit into a pull request, or delete the
  copy, then install again;
- an **unmanaged** directory. If it is an unmodified older copy, `--adopt`
  takes ownership when its content matches the source apart from CRLF line
  endings. Otherwise move it aside;
- a source directory with **uncommitted changes**, unless you pass
  `--allow-dirty-source`. The manifest then records `source_clean: false`.

`install` fixes a `provenance-stale` copy by rewriting only its manifest
(reported as `refreshed`), so after a merge you can reinstall from `main` and
every copy points at a commit on `main`.

If an install fails part-way, it exits non-zero, says how many directories
completed, and reports the failed directory's actual state after the failure.
A new directory is rolled back and the result is checked: anything that
couldn't be removed is listed. An updated or adopted directory isn't rolled
back; it keeps its old manifest (or stays unmanaged), and the report says
whether it can be adopted again.

## Guarantees

- `install` and `verify` read a clean source from Git at the recorded commit,
  so the manifest's commit is exactly what produced the installed bytes.
- Each target is rechecked immediately before it is written; if anything
  changed since planning, it is refused and left alone.

## Validation

`validate` is deliberately stricter and narrower than skill-creator's
`quick_validate.py`: frontmatter must be flat `key: value` lines with
single-line values (keys `name`, `description`, `license`, `allowed-tools`),
and files may not contain `TODO` markers, CR line endings, or relative links
that don't resolve inside the skill. The full contract is in
`docs/change-records/FW-MAINTAINER-SKILLS-01.md`.
