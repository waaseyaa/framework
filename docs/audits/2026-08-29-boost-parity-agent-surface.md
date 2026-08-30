# Agent Developer-Surface Audit — Waaseyaa vs. Laravel Boost

**Date:** 2026-08-29
**Scope:** `packages/bimaaji`, `packages/mcp`, `packages/ai-tools`, `packages/ai-agent/src/Tool/*`, `skills/waaseyaa/*`, metapackage require-graphs
**Reference point:** Laravel Boost as documented at `laravel.com/docs/12.x/boost` (fetched 2026-08-29)
**Status:** findings only — no code changed

---

## Reconciliation — 2026-08-30 (#2653)

This document was written on 2026-08-29 and never committed. It is committed here
**with its original findings unchanged**, and a `Status (2026-08-30)` block
appended to each one. The original observations are preserved verbatim so the
record stays readable; where a claim was wrong *when written*, the status block
says so rather than editing the claim away.

- **Reconciled against:** `origin/main` at `4cbe2c9bbf956a07c24f9af15245ef9bf27f0228`.
- **Legend:** `FIXED` — the defect is gone and current source proves it.
  `STILL OPEN` — the defect survives, with the issue that owns it where one exists.
  `SUPERSEDED` — the finding no longer describes the system, including the case
  where it never did.
- **Unverified** is stated explicitly where it applies; nothing here is guessed.
- The single largest change since this was written is **ADR-022**
  (`docs/adr/022-ai-development-package-and-local-operator-trust-boundary.md`,
  #2654, `46290e18b`, PR #2669), which settles the identity and packaging
  questions §1 raises before D2/D4/D5 can be built, and the **#2653** program
  ledger, which now owns D1–D5 and most of §3 as sequenced child issues.
- D6–D8 are new: findings discovered *during* the follow-up work that were never
  in the 2026-08-29 pass.

---

## 1. The framing problem

Boost and the Waaseyaa agent surface are solving **two different problems**, and
the repo has quietly conflated them.

| | Boost | Waaseyaa today |
|---|---|---|
| Who is the agent? | The *developer's* coding agent (Claude Code, Cursor) | An agent *operating the deployed app* |
| What does it touch? | The dev's local checkout + local DB + local logs | Live entities, content, revisions |
| Transport | stdio, `php artisan boost:mcp`, zero auth | Streamable HTTP `/mcp`, OAuth / durable bearer tokens |
| Blast radius | One developer's machine | Production data |
| Install | `composer require --dev` + `boost:install` | Hand-wired |

Waaseyaa has built the **harder** of the two (an authenticated, capability-scoped,
audited, sovereignty-guarded runtime agent surface — Boost has nothing like it)
and has **not** built the easier, higher-daily-leverage one. `bimaaji` is the
piece that was *aiming* at the Boost slot, and it is the piece that does not
currently reach a consumer.

> **Status (2026-08-30): FIXED as a framing gap — the distinction is now written
> down and binding.** ADR-022
> (`docs/adr/022-ai-development-package-and-local-operator-trust-boundary.md`)
> separates the two planes explicitly and settles four questions before any of it
> is built. Its C-1 section reaches the same conclusion this table gestures at,
> from the other direction and with citations: a CLI process runs under SAPI `cli`,
> which is not in `HttpKernel::DEV_FALLBACK_SAPIS`
> (`packages/foundation/src/Kernel/HttpKernel.php:1015`), and every framework agent
> tool enforces its capability against the supplied account
> (`packages/ai-tools/src/AbstractAgentTool.php:216-226`) — so a naively-built
> stdio server returns `forbidden` for every call. The ADR refuses the
> `DevAdminAccount` shortcut and specifies a never-persisted `LocalOperatorPrincipal`
> instead.
>
> The plane is designed, not built: the ADR's status line records it as *Accepted
> on merge*, discharged by **#2655**, **#2657**, **#2658** and **#2659**, all of
> which are open. So the substance of §1 — Waaseyaa has the harder surface and not
> the easier one — is **STILL OPEN as an implementation gap**, with a decided
> design underneath it.

---

## 2. Blocking defects (P0)

### D1 — `bimaaji:install` cannot work in any consumer install

`BimaajiServiceProvider::resolveSkillsDirectory()` falls back to
`<projectRoot>/skills/waaseyaa`. That directory exists **only in this monorepo**.

- `git ls-files packages | grep SKILL.md` → zero hits. No package ships the skill set.
- `skills/` sits at the monorepo root, so it reaches Packagist only inside
  `waaseyaa/framework` — and lands at `vendor/waaseyaa/framework/skills/waaseyaa`,
  not `<projectRoot>/skills/waaseyaa`.

Net effect: a downstream consumer running `bin/waaseyaa bimaaji:install` gets
`bimaaji:install: no skills discovered` unless they manually set
`bimaaji.skills_directory`. The command works only in the repository that
doesn't need it.

**Fix shape:** ship the skill set *inside* `packages/bimaaji/resources/skills/`
(copied or symlinked at release-cut, gated like `check-admin-dist-fresh`), and
resolve from the package's own directory with the project root as an *override*,
not the default.

> **Status (2026-08-30): FIXED — #2656 (`aa0d70d2f`, PR #2683).** The stated fix
> shape shipped, and shipped stronger than sketched.
>
> - `packages/bimaaji/src/BimaajiServiceProvider.php:417-425` now takes
>   `bimaaji.skills_directory` as the *override* and
>   `PackagedSkillResources::directory()` as the default, exactly inverting the
>   old precedence.
> - `packages/bimaaji/src/Install/PackagedSkillResources.php:39-42` resolves on
>   `dirname(__DIR__, 2) . '/resources/skills'` — anchored on the running class
>   file, so it follows the package wherever Composer installs it and never
>   guesses at a project root. The class docblock (`:10-21`) records the defect
>   it closes.
> - All eleven skills are tracked package resources under
>   `packages/bimaaji/resources/skills/<id>/`, and the monorepo-root `skills/`
>   directory is **deleted** — so the audit's `git ls-files packages | grep
>   SKILL.md` probe now returns hits rather than zero.
> - Freshness is a gate, and a **required** one: `ci/bimaaji-skill-resources`
>   (`.github/workflows/ci.yml:1383`) appears in the `main-protection` ruleset's
>   required-status-check roster, which is the `check-admin-dist-fresh`-shaped
>   control the fix shape asked for.
>
> One thing shipped that the fix shape did not name: `SkillSetParser` used to
> return `[]` for a missing directory and skip an unreadable document, which is
> how a packaged consumer got `no skills discovered` with no way to distinguish an
> absent install from one bad file. It now raises `SkillResourceException` and
> distinguishes the two (`packages/bimaaji/src/Install/SkillSetParser.php:27-31`).
>
> Cross-reference: this is the same fix recorded against S4 in
> `2026-08-29-skeleton-audit.md`, which is where the defect was felt.

### D2 — No metapackage carries the agent surface

Require-graphs as of `alpha.298`:

- `core` → 19 packages, none agent-facing
- `cms` → `core` + content types + `api`/`ssr`/`cli`
- `full` → `cms` + `ai-pipeline`, `ai-schema`, `ai-tools`, `ai-vector`, `attachment`, `relationship`, `search`, `structured-import`

`waaseyaa/bimaaji`, `waaseyaa/mcp`, `waaseyaa/ai-agent`, and `waaseyaa/wayfinding`
are in **none** of them. A consumer who installs `waaseyaa/full` — the maximal
curated option — gets `ai-tools` (the registry and the `entity.*` tools) but no
MCP endpoint to reach them through, no bimaaji graph, and no install command.

Boost's whole value proposition is `composer require laravel/boost --dev` →
`php artisan boost:install` → done. Waaseyaa currently requires a consumer to
already know the names of three unlisted packages.

> **Status (2026-08-30): STILL OPEN — designed in ADR-022, owned by #2655.**
> Re-verified against `packages/full/composer.json`: the `require` block
> (`:6-16`) still ends at `waaseyaa/structured-import`, and `waaseyaa/ai-agent`
> and `waaseyaa/mcp` sit in **`suggest`** (`:17-22`), which installs nothing.
> `waaseyaa/bimaaji` and `waaseyaa/wayfinding` appear in no metapackage at all.
>
> One correction to the finding: `waaseyaa/bimaaji` *is* in the root
> `waaseyaa/framework` manifest's `require` (`composer.json:332`), so the dev
> skeleton does receive it — the S4 finding in the skeleton audit turns on
> precisely that. It is the three *metapackages* that carry none of the agent
> surface, which is what a Packagist consumer installs.
>
> The design has since been settled in a direction the finding's own suggestion
> ("consider a dedicated `waaseyaa/boost`-style dev metapackage") anticipated, and
> ADR-022 adds a constraint the audit did not see: `waaseyaa/ai-development` is a
> `require-dev` metapackage that owns no code and **must not require
> `waaseyaa/mcp`**, because `McpRouteProvider` registers `/mcp/write`
> unconditionally — so pulling that package in to obtain a transport would add an
> HTTP route to every application that installed a development tool. That is why
> #2657 (a transport-neutral registry bridge) sits between #2655 and #2659 rather
> than the metapackage simply requiring `mcp`.

### D3 — `bimaaji_search_specs` and the `spec_index` graph section are inert by default

`resolveSpecsDirectory()` returns `null` unless `bimaaji.specs_directory` is
explicitly present in config. `SpecIndexProvider::provide()` then iterates an
empty directory list and returns `data: []`.

Combined with the fact that `docs/specs/*.md` ships in no package, the
Boost-equivalent "Search Docs" tool returns nothing for every consumer, in every
default configuration. This is the single highest-value Boost feature (17,000
indexed chunks, semantic search) and our analogue is a substring match over a
directory that isn't there.

> **Status (2026-08-30): STILL OPEN — owned by #2661 → #2662, and explicitly
> cited by both.** `packages/bimaaji/src/BimaajiServiceProvider.php:388-402` is
> unchanged: `resolveSpecsDirectory()` returns `null` unless
> `bimaaji.specs_directory` is present as an explicit non-empty string. `docs/specs/`
> still ships in no package (see S5 in the skeleton audit — the root
> `waaseyaa/framework` tarball is the only path by which it reaches a consumer,
> at a location nothing resolves).
>
> The finding is now load-bearing beyond itself, in a way worth recording.
> ADR-022's D-7.1 default-tool roster lists `bimaaji_search_specs` **while it is
> inert**, deliberately, so that re-adding it later does not read as a widening of
> the default. #2662 then carries an added acceptance criterion (2026-08-29, from
> the ADR-022 review) requiring a roster review at *activation* time, precisely
> because the cost of that choice is that a listed-but-inert entry would otherwise
> become a working documentation-search surface with no roster change. #2662 also
> requires that an empty or missing corpus "fails diagnostically rather than
> returning success-shaped emptiness" — which is the exact failure mode this
> finding describes.
>
> Two of the finding's framing points survive intact: the substring-vs-semantic
> gap, and the sequencing. #2662's acceptance states that the first release has no
> embedding dependency and does not wait for #1606 — so the delivery is SQLite
> FTS5 with citations first, vector search later, rather than the semantic search
> Boost ships.

### D4 — No stdio transport

`grep -rli stdio packages --include='*.php'` returns only
`ai-agent/src/Mcp/StreamableHttpMcpClient.php` — an *outbound* client. There is
no `mcp:serve` command; `ls packages/cli/src/Provider/*.php | xargs grep 'mcp:'`
is empty.

The only way to attach a coding agent is to boot an HTTP server, expose `/mcp`,
and satisfy `PublicAnonymousAuth` (read tier) or a durable bearer token (write
tier). For a developer pointing Claude Code at their own checkout that is
disproportionate friction. Boost is one process, no port, no token.

> **Status (2026-08-30): STILL OPEN — owned by #2659, gated on #2657 and #2658.**
> Re-verified, both probes unchanged: a case-insensitive `stdio` search across
> `packages/**/*.php` still returns only
> `packages/ai-agent/src/Mcp/StreamableHttpMcpClient.php` and its unit test, and
> `mcp:serve` appears nowhere in the tree.
>
> ADR-022 explains why this could not simply be built when the audit was written,
> and the ordering is now explicit in the ADR's own status line: **#2659 must not
> be implemented before #2657's design is separately accepted**, and #2658 must
> not be implemented before ADR-022 is accepted. The reason is C-1 — a stdio
> server stood up without settling identity first returns `forbidden` on every
> call, so shipping the transport ahead of the principal would have produced a
> working process that answers nothing. #2659's own scope adds cross-platform
> executable resolution, which the finding did not raise.

### D5 — Install writes guideline files but never registers the MCP server

`ClaudeClientTransformer::targetFiles()` (and its six siblings) emit
`.claude/skills/waaseyaa-<id>.md` + `.claude/CLAUDE-WAASEYAA.md`. No transformer
writes `.mcp.json`, and nothing prints the `claude mcp add` / `codex mcp add`
equivalent. `grep -rln 'mcpServers' packages --include='*.php'` → zero.

So even once D1/D2/D4 are fixed, `bimaaji:install` delivers the *guidelines* half
of Boost and silently omits the *tools* half.

> **Status (2026-08-30): STILL OPEN — owned by #2663.** Re-verified: `mcpServers`
> appears in no PHP file under `packages/`, and no file writes `.mcp.json`. The
> seven transformers remain
> (`packages/bimaaji/src/Install/Client/{Claude,Codex,Copilot,Cursor,Gemini,Junie,Windsurf}ClientTransformer.php`),
> and they still emit guidance only.
>
> Two things around the finding did change, both from #2656. The install is now
> **marker-bounded and re-runnable** — `ManagedRegion` splices between
> `<!-- waaseyaa:bimaaji:install BEGIN -->` / `END`
> (`packages/bimaaji/src/Install/ManagedRegion.php:11-36`) and preserves every
> byte outside the markers, and `.waaseyaa/bimaaji-install.json` records what was
> generated (`packages/bimaaji/src/Install/InstalledManifest.php:23-35`), with
> ownership only ever narrowed by the pruner, never widened. And the *skills* half
> of the guidelines is now genuinely delivered to a consumer (D1). So the shape of
> the gap has sharpened rather than moved: the install is a good install that
> writes the wrong half.
>
> #2663's acceptance goes further than the finding's fix shape in one respect that
> matters: machine-specific paths must stay local-only and are covered by **#2648**,
> so a generated MCP descriptor must not become the next thing that leaks a
> developer's home directory into a production artifact.

---

## 3. Capability gaps (P1)

Boost's current tool list is nine tools. Mapping:

| Boost tool | Waaseyaa equivalent | Verdict |
|---|---|---|
| Application Info (PHP/framework versions, DB engine, packages, models) | partial — `bimaaji_introspect_section('entities')`; no versions, no DB engine, no package list | **gap** |
| Database Schema | none as a tool (`schema:list` / `schema:check` are CLI-only) | **gap** |
| Database Query | none | **gap** (deliberate? needs a decision) |
| Database Connections | none | **gap** |
| Last Error | none | **gap** |
| Read Log Entries | none | **gap** |
| Browser Logs | none; `packages/telescope/` is `README.md` + `composer.json` with no `src/` | **gap** |
| Get Absolute URL | none | **gap** |
| Search Docs | `bimaaji_search_specs` — substring, inert by default (D3) | **broken** |
| — | `bimaaji_introspect_graph` / `_section` (7 sections) | **ahead** |
| — | `bimaaji_propose_mutation` → `bimaaji_generate_patch` (AST-safe, never writes) | **ahead** |

Also missing relative to Boost:

- **No `boost:update` analogue.** Guidelines are written once and drift. Boost
  documents wiring `boost:update` into `post-update-cmd`.
- **No third-party contribution hook.** Boost lets any package ship
  `resources/boost/guidelines/core.blade.php` and
  `resources/boost/skills/{name}/SKILL.md`. Waaseyaa's `SkillSetParser` scans
  exactly one directory, one level deep. A distribution extension (`genealogy`)
  cannot contribute a skill.
- **No custom/override layer.** Boost's `.ai/guidelines/*` and `.ai/skills/*`
  override built-ins by matching path/name. Waaseyaa has no consumer override seam.
- **No version-conditional guidelines.** Boost ships `core` + per-major variants.
  Ours are flat.
- **Semantic search unused.** `waaseyaa/ai-vector` exists and is in `full`.
  Nothing indexes `docs/specs/` into it. The pieces for a real docs API are
  present and unconnected.

> **Status (2026-08-30): the tool table is STILL OPEN in full; the five follow-on
> bullets are one FIXED-in-part and four STILL OPEN.**
>
> **The tool table — STILL OPEN, and now deliberately deferred.** An inventory of
> every `#[AsAgentTool]` class under `packages/**/src/` returns the same set the
> audit saw: the five Bimaaji adapters, five Wayfinding trail/beacon tools, the
> nine `ai-tools` `entity.*` tools plus relationship traversal and vector search.
> Nothing was added for application info, database schema or connections, last
> error, log entries, browser logs, or absolute URL. `packages/telescope/` still
> contains exactly `README.md` and `composer.json`.
>
> That block of rows now has an owner and a reason: **#2666** ("define
> sovereignty-aware logs, last-error, URL, and schema-shape diagnostics") covers
> logs, last error, browser diagnostics, URL resolution and schema-shape
> inspection, and #2653 places it under *Deferred expansion* — outside the launch
> path until S5 field evidence justifies promotion. Its non-goals restate the
> audit's own closing paragraph almost exactly: no arbitrary eval or Tinker, no
> raw write query, no default content or entity data access. The Database Query
> row's parenthetical ("deliberate? needs a decision") is therefore **answered**:
> deliberate, and refused. #2658's acceptance narrows the near-term default to
> "docs, framework/application introspection, and non-content diagnostics", which
> is the subset of this table that will actually arrive first.
>
> **`boost:update` analogue — FIXED in part, STILL OPEN as a hook.** Re-running
> `bimaaji:install` is now safe and idempotent (see D5: marker-bounded splice plus
> an install manifest), so guidelines can be refreshed after an upgrade without
> destroying hand-authored content, and `skeleton/CLAUDE.md:113-115` documents
> exactly that. What is still missing is the automatic half: nothing wires it into
> `post-update-cmd` (`skeleton/composer.json:35-46` defines `audit-site`,
> `site-verify`, `dev`, `regen-lock` and `post-create-project-cmd`, and no
> post-update hook). Owned by **#2664**, whose acceptance names `ai:update
> --check/apply` and `ai:verify` on one hash/version engine, with "Composer
> post-update touches only generated/bounded regions".
>
> **Third-party contribution hook — STILL OPEN.**
> `packages/bimaaji/src/Install/SkillSetParser.php:17-20` still walks "one level of
> a base directory". `bimaaji.skills_directory` *replaces* the packaged default
> rather than layering onto it
> (`packages/bimaaji/src/BimaajiServiceProvider.php:417-425`), so a distribution
> extension still cannot contribute a skill. Owned by **#2660**.
>
> **Custom/override layer — STILL OPEN, and #2660 inverts the model.** Rather than
> a `.ai/*` overlay on top of built-ins, #2660's acceptance makes "AGENTS.md and
> `.ai` authoritative" with the Claude, Codex, Cursor and MCP layouts as
> *generated adapters*, preserving human content through source hashes and
> marker-bounded merges. That is a different and better answer to the same
> problem, so this bullet should be read as superseded-in-approach rather than
> simply pending.
>
> **Version-conditional guidelines — STILL OPEN, unfiled as such.** Nothing
> version-conditions the skill set today. The nearest owner is **#2662**, whose
> acceptance requires results to carry the installed package/framework version —
> but that is version-*stamped retrieval*, not version-*conditional authoring*,
> and no issue read for this reconciliation covers the latter.
>
> **Semantic search unused — STILL OPEN, and deliberately not first.** `waaseyaa/ai-vector`
> is still in `packages/full/composer.json:10` and still indexes no specs. #2662
> explicitly ships without an embedding dependency and does not wait for #1606, so
> the first documentation search is FTS5 with citations. The audit's observation —
> the pieces are present and unconnected — stands; the sequencing judgement it
> implies (connect them) has been decided against for the first release.

---

## 4. Where Waaseyaa is materially ahead

Worth stating plainly, because the gap list above reads one-sided:

- **Capability-scoped tool registry** — `CapabilityScopedToolRegistry` intersects
  the tier registry with the token's scopes per request; scopes narrow, never
  broaden. Boost has no authorization model at all.
- **Durable bearer tokens** — hashed at rest, audience-bound, mandatory expiry,
  constant-time verify, atomic rotation, one-time secret reveal.
- **OAuth 2.1 resource-server mode** — RFC 9728 discovery, `WWW-Authenticate`
  scope challenges, validator port for an enterprise IdP.
- **Strict audit ledger** — fail-closed reserve/finalize on the write tier.
- **Sovereignty guardrails** — `MutationValidator` runs OCAP-aligned policy
  before structural validation.
- **Mutation → patch protocol** — validated intent, AST-safe patch generation,
  never writes to disk, independent unsafe-identifier evaluation as defense in depth.
- **Protocol breadth** — `2026-07-28` plus three legacy revisions, server card at
  `/.well-known/mcp.json`, official Registry `server.json` projection.
- **Seven client transformers** vs Boost's agent-class extension point.

None of this is a substitute for D1–D5. It is the reason the fixes are worth
doing rather than starting over.

> **Status (2026-08-30): spot-checked, still standing; not exhaustively
> re-verified.** Three anchors confirmed present:
> `packages/mcp/src/CapabilityScopedToolRegistry.php`, the server card route at
> `packages/mcp/src/McpRouteProvider.php:73`
> (`RouteBuilder::create('/.well-known/mcp.json')`), and the seven client
> transformers under `packages/bimaaji/src/Install/Client/`. The bearer-token,
> OAuth resource-server, audit-ledger and sovereignty-guardrail claims were **not**
> re-verified line by line in this pass and are carried forward as of 2026-08-29.
>
> One addition. ADR-022 takes the audit's closing sentence — that this surface is
> the reason to fix rather than restart — and makes it a constraint on the fix:
> the local plane must compose the existing capability, audit and sovereignty
> machinery rather than fork a second, weaker one, and must refuse to construct or
> dispatch when the resolved audit ledger is absent or is the record-nothing one.
> #2657's acceptance carries the same requirement from the other side: "capability
> checks, audit metadata, and tool schemas are preserved", and "existing HTTP
> behavior remains compatible".

---

## 5. Housekeeping found in passing

- `packages/bimaaji/mcp/node_modules/` — ~90 npm packages (`@modelcontextprotocol/sdk`,
  hono, express, zod) on disk with **no source file beside them**. Untracked and
  gitignored via `packages/bimaaji/.gitignore`, so CI is unaffected, but it is the
  fossil of an abandoned Node MCP sidecar. `docs/specs/mcp-endpoint.md:1254` already
  records "No Node sidecar … are involved." Delete the directory.
- `packages/telescope/` is listed in the Layer 6 table in `CLAUDE.md` and has no
  `src/`. Either implement it (it is the natural home for Browser Logs / Last Error
  / Read Log Entries) or drop it from the layer table.
- `docs/specs/bimaaji.md` "Implementation Status" still lists M5
  (`bimaaji-install-command-01KS5W0S`) under **Deferred (post-M3)** while
  `packages/bimaaji/src/Install/` is fully implemented with seven transformers.
  Spec drift.

> **Status (2026-08-30): one FIXED, one STILL OPEN, one UNVERIFIABLE from here.**
>
> - **`packages/bimaaji/mcp/node_modules/` — UNVERIFIED, and unverifiable from a
>   fresh worktree.** The directory is untracked, so it exists per-checkout and
>   its absence here proves nothing about the maintainer's working tree. What can
>   be confirmed: nothing under `packages/bimaaji/mcp/` is tracked in git;
>   `packages/bimaaji/.gitignore` still contains exactly `mcp/node_modules/`, so
>   the ignore rule that was written for the sidecar survives it; and the spec
>   statement the finding cites is still live, now at
>   `docs/specs/mcp-endpoint.md:1283` ("sidecar and no MCP-local registry contract
>   are involved") rather than `:1254`. The recommendation to delete the directory
>   — and, with it, the orphaned ignore rule — stands and is unfiled.
> - **`packages/telescope/` — STILL OPEN, unfiled.** Unchanged: the package
>   contains exactly `README.md` and `composer.json`, and `CLAUDE.md:110` still
>   lists `telescope` in the Layer 6 Interfaces row. The finding's two options are
>   now three, because there is a third document in play:
>   `docs/adr/020-telescope-codified-context.md` reasons *from* the package's
>   existing capabilities, which is a claim the empty directory does not support.
>   Any resolution should reconcile that ADR as well as the layer table. The
>   "natural home for Browser Logs / Last Error / Read Log Entries" judgement is
>   now adjacent to **#2666**, which defines exactly those diagnostics — but #2666
>   names no package, so this is not attributed to it.
> - **`docs/specs/bimaaji.md` spec drift — FIXED — #2656 (`aa0d70d2f`, PR #2683).**
>   `docs/specs/bimaaji.md:40` now reads "**Shipped (M5
>   `bimaaji-install-command-01KS5W0S`, 2026-05-23):**", and
>   `docs/specs/bimaaji.md:5` carries a `Spec reviewed 2026-08-29 - #2656` comment
>   that records both the correction and the change of source-of-truth for the
>   skills. Detail moved to `docs/specs/bimaaji-install.md`.

---

## 6. Recommended sequence

Ordered by leverage per unit of work:

1. **D2 + D1 together** — add `bimaaji` + `mcp` to `full` (and consider a
   dedicated `waaseyaa/boost`-style dev metapackage), ship the skill set inside
   `packages/bimaaji/resources/skills/` with a freshness gate. Without this,
   nothing else in the audit reaches a user.
2. **D4 + D5** — add `bin/waaseyaa mcp:serve --stdio` and make the install
   command emit client MCP registration. This is what turns the existing tool
   surface into something a developer actually attaches.
3. **D3 + docs API** — ship `docs/specs/` as a package resource, index it into
   `ai-vector`, and replace the substring search. This is Boost's highest-value
   feature and our infrastructure for it already exists.
4. **P1 tool gaps** — application info, database schema/connections, last error,
   log entries. These are read-only introspection over subsystems Waaseyaa
   already owns (`Foundation\Diagnostic`, `schema:*`, the logger).
5. **Extensibility** — third-party guideline/skill contribution + consumer
   override layer, so `genealogy` and downstream distributions can participate.

Deliberately **not** recommended without a separate decision: a Tinker/eval tool
and an arbitrary Database Query tool. Boost can afford those because it is
stdio-local and dev-only; Waaseyaa's MCP surface is network-reachable and
production-shaped, and the two would punch straight through the capability model.
If they land, they belong behind a `WAASEYAA_DEV`-gated stdio-only tier.

> **Status (2026-08-30): the sequence was adopted, split, and re-ordered — and
> the split is the substantive change.** #2653's critical path is
> `#2649 + #2644 → #2654 → #2655/#2657/#2658 → #2659 → #2663/#2664 → #2665`, with
> `#2656 → #2660` and `#2229 → #2661 → #2662` running in parallel and everything
> converging on #2665.
>
> Mapping this list onto it: **item 1** split — D1 landed first and alone (#2656),
> and D2 is now #2655, *behind* the ADR rather than beside D1, because the ADR had
> to settle what the metapackage may require. **Item 2** split into #2657 → #2659
> (transport) and #2663 (client registration), and gained #2658 in front of both,
> because an stdio transport without a principal answers `forbidden`. **Item 3**
> became #2661 → #2662 and dropped `ai-vector` from the first release. **Item 4**
> became #2666 and was deferred out of the launch path entirely. **Item 5** became
> #2660 and inverted into an AGENTS.md-authoritative model.
>
> The closing paragraph's refusal was **adopted verbatim in substance**: #2666's
> non-goals are "no arbitrary eval/Tinker, no raw write query, no default
> content/entity data access". The `WAASEYAA_DEV`-gated stdio-only tier the audit
> offered as the condition for landing them is close to what ADR-022 specifies for
> the *whole* local plane — a `require-dev` package absent after `composer install
> --no-dev`, with the ADR explicit that packaging is the weaker control and the
> runtime refusal is the real one.

---

## 7. Found during the follow-up work (new, not in the original pass)

These were discovered while acting on the findings above and were never part of
the 2026-08-29 audit. They get the same treatment.

### D6 — Root `AGENTS.md` is a multi-writer path with single-writer ownership semantics — STILL OPEN — #2686

#2656 (PR #2683) corrected the Codex transformer's target from `.codex/AGENTS.md`
— which is the personal `$CODEX_HOME` scope, not project scope — to the
repository-root `AGENTS.md`. That correction is right, and it creates a boundary
the install design does not yet handle.

Root `AGENTS.md` is a **multi-writer path**: Codex reads it as its project
contract, Junie's documentation names it as a valid location alongside
`.junie/AGENTS.md`, Devin Desktop reads it, and users hand-author it routinely.
#2683's marker-bounded splice protects hand-authored content *outside* the managed
region (`packages/bimaaji/src/Install/ManagedRegion.php:11-36`) and
`.waaseyaa/bimaaji-install.json` records what Bimaaji generated
(`packages/bimaaji/src/Install/InstalledManifest.php:23-35`). Neither mechanism
handles two *managed* clients claiming the same path: the manifest records a path
once per client with a single digest of the bytes on disk, so a later run that
retires one client's skill set can prune or neutralise a region another client
still owns.

Latent, not live — today only Codex targets root `AGENTS.md`. It becomes live the
moment a second transformer is pointed there. #2686 must decide whether a shared
path may carry more than one managed region or is owned exclusively, and either
way must prove that retiring one client leaves another's region byte-identical.

This is the same seam #2660 widens by making `AGENTS.md` and `.ai` authoritative
across clients, so the two should be sequenced together.

### D7 — The file-level sandbox target guard is proven on Linux and reasoned-but-unexecuted on Windows — STILL OPEN — #2693

A proof gap, not a known defect, and worth recording as such so a later pass does
not read it as either.

#2656 (PR #2683) gave `BimaajiInstallCommand::resolvePathInSandbox()` a layered
containment boundary: textual (empty, absolute, or `..`), ancestor (the nearest
existing ancestor must resolve inside the project root), and target (the target
itself must not be a link, and an existing target must resolve inside the project
root). Layer 3 exists specifically because `is_link()` does not report a Windows
directory junction, so the guard resolves with `realpath()` rather than trusting
`is_link()`.

`bin/check-bimaaji-junction-containment.ps1`, running in
`ci/skeleton-create-project-windows`, proves the platform premise on a real
Windows runner. What it cannot cover: a Windows directory junction is
directory-only, so it exercises layer 2, not layer 3. The file-level case needs a
Windows **file symbolic link**, which normally requires
`SeCreateSymbolicLinkPrivilege` or Developer Mode — the exact privilege
requirement junctions were chosen to avoid. Layer 3 is proven on Linux by six
sentinel tests in `InstallCommandSandboxContainmentTest` and unexecuted on Windows.

### D8 — The agent contract documents a `spec-reviewed:` trailer the drift detector will not accept — STILL OPEN — #2675

Relevant to this audit because it is a rule every agent working in this repository
is told to follow, and following it literally produces a warning every time.

`docs/governance/agent-contract.md:59-61` reads: "Review spec impact explicitly.
Update enduring contracts when behavior or architecture changes; **otherwise** use
the supported `spec-reviewed:` commit trailer with a concrete reason." "Otherwise"
reads as: if you did not update a spec, carry a trailer. That is not what
`tools/drift-detector.sh` accepts. It discards an acknowledgement in two cases —
`:395`, a token that is not a spec path, and `:405`, a spec path outside the
affected set. Together those mean that **for a change set with no affected specs
there is no conforming trailer at all**: a prose reason is discarded by `:395`,
and naming any real spec path is discarded by `:405`.

The tool is right and the document is wrong. An acknowledgement exists to record
that an *affected* enduring contract was reviewed and deliberately left unchanged;
when nothing enduring is affected there is nothing to acknowledge, and a blanket
trailer is the unfalsifiable ceremony the gate exists to prevent — which is why
`tools/drift-detector.sh:387` already rejects `spec-reviewed: all`. #2675 corrects
the documentation and explicitly does **not** broaden the detector.

Observed on PR #2672 (#2651), whose first pass followed the contract literally and
was discarded at `:395`, then reformatted to the documented grammar and discarded
at `:405`. `CLAUDE.md` carries the same misleading short form and is named in the
acceptance. This reconciliation PR follows the corrected rule: it affects no spec,
so it carries no trailer.
