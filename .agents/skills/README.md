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

1. Edit the files here on a branch and open a pull request like any other
   change. `tests/Architecture/MaintainerSkillsTest.php` and
   `php bin/maintainer-skills validate` check the structure.
2. After it merges, refresh your local copies from an up-to-date `main`:

   ```bash
   php bin/maintainer-skills install
   php bin/maintainer-skills verify
   ```

Do not edit the installed copies. They are generated.

## Where the skills are installed

Codex finds this directory by itself when it runs inside the Framework
checkout. For every other repository, and for Claude Code, `install` copies each
skill into:

- `$CODEX_HOME/skills` (default `~/.codex/skills`)
- `$CLAUDE_CONFIG_DIR/skills` (default `~/.claude/skills`)

Pass `--target=DIR` (repeatable) to choose other directories.

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

## Validation

`validate` is deliberately stricter and narrower than skill-creator's
`quick_validate.py`: frontmatter must be flat `key: value` lines with
single-line values (keys `name`, `description`, `license`, `allowed-tools`),
and files may not contain `TODO` markers, CR line endings, or relative links
that don't resolve inside the skill. The full contract is in
`docs/change-records/FW-MAINTAINER-SKILLS-01.md`.
