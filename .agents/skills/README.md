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
commit and a SHA-256 for every file. `verify` reports each copy as `current`,
`stale` (the source changed), `drifted` (someone edited the copy), `missing`,
or `unmanaged` (a directory the installer did not create).

`install` decides every target before writing anything, and it refuses rather
than overwriting:

- a **drifted** copy. Move the local edit into a pull request, or delete the
  copy, then install again;
- an **unmanaged** directory. If it is an unmodified older copy, `--adopt`
  takes ownership when its content matches the source apart from CRLF line
  endings. Otherwise move it aside;
- a source directory with **uncommitted changes**, unless you pass
  `--allow-dirty-source`. The manifest then records `source_clean: false`.
