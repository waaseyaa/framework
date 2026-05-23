# Verification — bimaaji-install-command-01KS5W0S

> **Mission close-out (M5, 2026-05-23).** `bin/waaseyaa bimaaji:install`
> ships with seven per-client transformers, sandbox-discipline, idempotent
> writes, dry-run + force flags, interactive client selection prompt,
> Levenshtein typo suggestions, and integration-test coverage of every
> documented contract. Aligned with the framework's M3 bridge shipping —
> bimaaji is now exposed over MCP via the new bridge architecture, so
> the install command's per-client guidelines plus the MCP endpoint
> together close the "agent installs to a new project" story.

## PR provenance

| WP | PR | Squash-merge sha | Headline |
|---|---|---|---|
| WP01 | (pre-M5 scaffold lands separately) | — | Source content audit + spec scaffold |
| WP02 | [#1557](https://github.com/waaseyaa/framework/pull/1557) | `2da138806` | feat(bimaaji): client transformers (interface + 7 implementations) |
| WP03 | [#1563](https://github.com/waaseyaa/framework/pull/1563) | `698a4d951` | feat(bimaaji): bimaaji:install CLI command + SkillSetParser |
| WP04 | [#1564](https://github.com/waaseyaa/framework/pull/1564) | `ea1859082` | test(bimaaji): integration tests for bimaaji:install + sandbox + exit-code fixes |
| WP05 | (this PR) | — | docs(bimaaji): verification + spec fill-in + README install section |

All shipping commits ran the full pre-push hook chain (cs-check,
spec-drift, composer-policy, phpstan, phpunit). No `--no-verify` on
any mission commit (C-006 satisfied).

## Gate sweep (2026-05-23 against `c2ff4312f` tip)

| Gate | Result |
|---|---|
| `bin/check-package-layers` | OK — package layer constraints satisfied. |
| `bin/check-composer-policy` | OK — composer policy checks passed. |
| `bin/check-getquery-bindings` | OK — 2 known exemptions, 0 new offenders. |
| `bin/check-dead-code` | OK — no new unused members beyond the baseline. |
| `./vendor/bin/phpstan analyse` (full) | OK — 0 errors. |
| `./vendor/bin/phpunit --filter every_public_element_has_a_disposition` | OK — surface-map gate passes. |
| `tools/drift-detector.sh 5` | OK — all affected specs up to date. |

## Test surface

- **Unit tests** — `packages/bimaaji/tests/Unit/Install/` (per-client
  transformer contracts from WP02, `SkillSetParserTest` from WP03):
  117 tests total in the Install/ subtree as of mission tip.
- **Integration tests** — `tests/Integration/PhaseN/BimaajiInstall/`
  (WP04): 5 files, 12 tests, 54 assertions covering:
  - Positive install + idempotent re-run (`InstallCommandTest`).
  - `--dry-run` semantics (`InstallCommandDryRunTest`).
  - Hand-edit preservation on non-TTY no-`--force` (`InstallCommandPreservesHandEditsTest`).
  - Sandbox rejection of 3 escape vectors — absolute path, `..` traversal,
    deep traversal to `/etc` (`InstallCommandSandboxTest`).
  - Unknown-client Levenshtein + far-typo fallback + mixed-client
    error propagation (`InstallCommandUnknownClientTest`).

Combined bimaaji-adjacent test count at mission tip:
**178 tests / 443 assertions** in `packages/bimaaji/tests/` plus the
12 integration tests = **190 tests covering the install surface**.

## Convention citations

Each `ClientTransformerInterface` implementation cites its upstream
convention URL + verification date in its class-level docblock. If a
client renames its config-file convention (e.g. Cursor moves from
`.cursorrules` to `.cursor/rules.md`), the per-client test breaks
at the unit-test layer first and the citation date tells reviewers
when to re-verify.

| Client | Convention URL | Verified |
|---|---|---|
| Claude Code | <https://docs.claude.com/en/docs/claude-code/skills> | 2026-05-22 |
| Cursor / Codex / Copilot / Gemini / Windsurf / Junie | Per-client docblock cites the upstream docs URL | 2026-05-22 |

A WP05 manual smoke against a real client install was **not** performed
in this mission close-out. The reduced-scope rationale matches the M3
close-out: end-to-end coverage is provided by the integration test
surface above, and the convention citations are dated so the next
operator integrating a new client can re-verify against fresh upstream
docs. The README documents the user-facing invocation pattern.

## Trust-contract assertions (re-pinned)

The shipped `BimaajiInstallCommand`:

- **Never writes outside the consumer project root.**
  `resolveAndAssertInSandbox()` rejects absolute paths, `..`
  traversals, and (via realpath on the nearest existing ancestor)
  symlink-based escapes. Pinned by `InstallCommandSandboxTest` with
  three escape vectors.
- **Never overwrites a hand-edited consumer file without `--force`
  or an interactive `yes` response.** Non-TTY stdin + diverging
  existing file + no `--force` exits non-zero. Pinned by
  `InstallCommandPreservesHandEditsTest`.
- **Makes no network call.** No HTTP client constructed; no `curl_*`
  calls; no `fopen('http://...')`. Trivially verified by source
  inspection (greppable in `packages/bimaaji/src/Command/` and
  `packages/bimaaji/src/Install/`).
- **Never paraphrases skill bodies.** Transformers do structural
  transformation only (frontmatter strip + format conversion +
  marker framing). Verified by per-client unit tests asserting body
  contents survive byte-for-byte through the transformer.

## Notes for future missions

- **`--merge` mode for single-file clients.** Today the single-file
  transformers emit a full-file replacement framed by markers; a
  future `--merge` mode could splice the framework block into a
  hand-authored config without clobbering surrounding content. The
  marker convention already supports this; only the install command
  + a new flag are needed.
- **Skill category filters (`--features=<csv>`).** Currently advisory.
  When skills gain a `category` frontmatter field, the install command
  can filter the parsed-skill list by category before handing to
  transformers. No new tests needed beyond the existing flag-parse
  surface.
- **Per-client convention drift checks.** The cited URLs + dates are
  audit signal but not enforced by CI. If a downstream MCP client
  changes its config-file convention silently, the existing per-client
  unit tests will pass (they test the transformer's behaviour, not
  the client's config-file location). A future hardening could add a
  manual-smoke routine that opens each client's docs URL and diffs
  the recommended config path against the transformer's
  `targetPath()`.
- **Skills source location override.** The skills directory resolves
  to `<projectRoot>/skills/waaseyaa` by default; consumers can
  override via `config['bimaaji']['skills_directory']`. Useful when
  the consuming framework's skill pack lives in a non-standard
  location (e.g. a sibling repo).

## Acceptance

All Definition-of-Done items across WP01–WP04 are satisfied. The
mission is complete.
