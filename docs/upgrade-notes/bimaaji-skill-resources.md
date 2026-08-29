# Upgrade Note: Bimaaji Agent Skill Resources

Issue #2656 moves the canonical Agent Skills into `waaseyaa/bimaaji`, changes
the Claude Code and Codex output layouts, makes `bimaaji:install` prune what it
generated, and turns `SkillSetParser::parse()` into a throwing method. **The
parse change is the one that can break code you wrote**; the rest changes files
in your project, not your PHP.

## `SkillSetParser::parse()` now throws instead of returning `[]`

`Waaseyaa\Bimaaji\Install\SkillSetParser` is `@api`. Before this release:

```php
$skills = $parser->parse();   // list<ParsedSkill>, possibly empty
if ($skills === []) {
    // missing directory? empty directory? one unreadable file? no way to tell
}
```

It returned an empty list when the directory was absent, and silently skipped
any `SKILL.md` it could not parse — so a single corrupt document looked exactly
like an empty install, and the failure text named a repository-relative path no
consumer project has.

Now:

```php
use Waaseyaa\Bimaaji\Install\SkillResourceException;
use Waaseyaa\Bimaaji\Install\SkillResourceFailure;

try {
    $skills = $parser->parse();   // non-empty-list<ParsedSkill>
} catch (SkillResourceException $e) {
    match ($e->failure) {
        // Directory absent, unreadable, or holding no <id>/SKILL.md.
        SkillResourceFailure::Missing => $this->reinstallOrFixConfiguration($e->directory),
        // One document exists but cannot be parsed. $e->skillFile names it.
        SkillResourceFailure::Corrupt => $this->repair($e->skillFile),
    };
}
```

- The return type is now `non-empty-list<ParsedSkill>`: a successful call always
  yields at least one skill.
- `$e->directory` is the resolved **absolute** directory. `$e->skillFile` is the
  offending document for `Corrupt`, and `null` for `Missing`.
- `$e->getMessage()` is already operator-ready and names the remedy, which
  differs by provenance: a packaged default suggests
  `composer reinstall waaseyaa/bimaaji`, a `bimaaji.skills_directory` override
  suggests correcting the override.

**If you call `parse()`**, wrap it or let the exception propagate — an
uncaught `SkillResourceException` is a `RuntimeException`. **If you only run
`bin/waaseyaa bimaaji:install`**, nothing changes for you: the command catches
it and prints the diagnostic.

## Where the skills come from

Default resolution now begins at the resources inside the installed package
(`vendor/waaseyaa/bimaaji/resources/skills`), resolved from the running class
file. The previous `<projectRoot>/skills/waaseyaa` fallback is gone; it only
ever existed in the framework monorepo, so `bimaaji:install` failed for every
downstream consumer. `bimaaji.skills_directory` still overrides.

If you were relying on the project-root fallback — a framework contributor
running the command inside a checkout of the framework itself — set
`bimaaji.skills_directory` explicitly, or rely on the packaged default, which is
now correct in the checkout too.

## Claude Code output moved to the documented layout

| Was | Now |
|---|---|
| `.claude/skills/waaseyaa-<id>.md` | `.claude/skills/waaseyaa-<id>/SKILL.md` |

A Claude Code project skill is a **directory** containing `SKILL.md`; the
command name comes from the directory name. A flat `.md` in `.claude/skills/`
is not a documented layout and is not discovered, so the previous output was
never loaded even though the install reported it as written. See
<https://code.claude.com/docs/en/skills>.

**Action required for anyone who installed the Claude client before this
release.** The old flat files predate the ownership manifest described below,
so the installer has no record that it wrote them and — by design — will not
delete a file it cannot prove it owns. Remove them by hand once:

```bash
rm -f .claude/skills/waaseyaa-*.md
./vendor/bin/waaseyaa bimaaji:install --client=claude
```

Check the glob before running it if you author your own skills under a
`waaseyaa-` prefix.

## Codex output moved to the repository-root `AGENTS.md`

| Was | Now |
|---|---|
| `.codex/AGENTS.md` | `AGENTS.md` (repository root) |

`.codex/AGENTS.md` is not a Codex discovery location at all: Codex reads a
plain `AGENTS.md` walking from the git root down to the working directory,
while `~/.codex/AGENTS.md` under `$CODEX_HOME` is the separate *personal*
scope that lives in your home directory. The old path was read by nothing. See
<https://learn.chatgpt.com/docs/agent-configuration/agents-md> and
<https://agents.md>.

The root `AGENTS.md` is shared rather than vendor-private — Devin Desktop and
JetBrains Junie read it too. Writing it is safe: the payload is marker-bounded,
so an existing hand-authored `AGENTS.md` keeps every byte outside the markers,
and a file with no markers still needs `--force` or an interactive
confirmation. Delete a stale `.codex/AGENTS.md` by hand for the same
no-recorded-ownership reason as above.

## The installer now prunes what it generated

`bimaaji:install` writes an ownership manifest at
`.waaseyaa/bimaaji-install.json` recording every path it generated, per client,
with the sha1 of the bytes it left on disk. **Commit it** — it is the provenance
for the generated files committed beside it.

On each run, a previously recorded target that the current skill set no longer
produces is resolved one of three ways:

| On-disk state | Outcome |
|---|---|
| Bytes still match the manifest | Deleted, and its directory removed if that leaves it empty. |
| Bytes differ, marker pair present | Kept. The managed region is replaced with a retirement notice; every byte outside the markers is preserved. |
| Bytes differ, no marker pair | Left completely untouched, and the ownership claim is released so no future run touches it either. |

Ownership is **recorded, never inferred**. A path absent from the manifest is
never touched, whatever it is named — a skill you author yourself at
`.claude/skills/waaseyaa-mine/` is safe. Supporting files you add inside a
generated skill directory are also safe: only the recorded `SKILL.md` is ever
removed, and the directory goes only if it is then empty.

Because the manifest is new, the first run after upgrading has nothing to prune.
Pruning starts working from the second run onward.

## Symlinked targets are now refused

`bimaaji:install` will not read, write, delete or rewrite a path that is a
symbolic link, and will not act on one that resolves outside the project root.
If you had symlinked a generated file — `.cursorrules` pointing at a shared
copy, say — the command now reports it and exits non-zero instead of following
the link. Replace the link with a regular file to have the installer manage it,
or point `bimaaji.skills_directory` at your shared source instead. A symlinked
*directory* that resolves inside the project is still fine; only the final path
component is refused.

The same boundary now covers `.waaseyaa/bimaaji-install.json`, and a manifest
that cannot be written is a non-zero exit rather than a warning.
## What did not change

- The skill documents themselves moved byte-for-byte; no guidance was rewritten.
- `bimaaji.skills_directory`, `--client`, `--features`, `--dry-run` and
  `--force` keep their meanings, and `--dry-run` still touches nothing —
  including the manifest.
- The Copilot (`.github/copilot-instructions.md`) and Gemini (`GEMINI.md`)
  target paths are unchanged and confirmed current.
- Cursor (`.cursorrules`), Windsurf (`.windsurfrules`) and Junie
  (`.junie/guidelines.md`) are unchanged in this release. All three are
  documented by their vendors as legacy-but-still-read; migrating them is
  tracked separately because each needs its own decision (Cursor moves to a
  multi-file `.cursor/rules/*.mdc` layout with required frontmatter, Windsurf
  has been rebranded to Devin Desktop with `.devin/rules/`, and Junie now
  prefers `.junie/AGENTS.md`, which overlaps the root `AGENTS.md` this release
  starts writing).
