# ADR-022 — the `waaseyaa/ai-development` package, the local-operator trust boundary, and the default tool profile

- **Status:** Proposed. This ADR is **Accepted on merge** of the pull request
  that introduces it. #2658 MUST NOT be implemented before that acceptance, and
  #2659 MUST NOT be implemented before #2657's design is separately accepted.
- **Date:** 2026-08-29
- **Anchor issue:** #2654 (parent program: #2653 · milestone S5 · AI-First Local Development)
- **Discharged by:** #2655 (metapackage), #2657 (registry bridge), #2658
  (`LocalOperatorPrincipal` + catalogue), #2659 (stdio transport)
- **Related ADRs:** ADR-004 (framework package collapse), ADR-019 (MCP tool
  access enforcement against the initiator account)

## Context

The S5 program (#2653) ships a local-only development plane so a standards-based
coding agent can introspect a Waaseyaa application over stdio. Before any of that
is built, four questions must be settled, because each one has a wrong answer that
is easy to reach and expensive to reverse.

Every claim below was verified against `main` at `c674ee734` and is cited to
`file:line`.

### C-1 — A naive stdio MCP server returns `forbidden` for every tool

`HttpKernel` grants the dev fallback admin account only under a SAPI allowlist:

- `packages/foundation/src/Kernel/HttpKernel.php:1015` —
  `private const array DEV_FALLBACK_SAPIS = ['cli-server', 'frankenphp'];`
- `packages/foundation/src/Kernel/HttpKernel.php:1017–1033` — the grant needs
  **all three** of that SAPI check, `isDevelopmentMode()`, and an explicit
  `config['auth']['dev_fallback_account'] === true`.
- `packages/foundation/src/Kernel/HttpKernel.php:648` — the resulting account (or
  `null`) is handed to `SessionMiddleware`.

A CLI command runs under SAPI `cli`, which is not in `DEV_FALLBACK_SAPIS`. A
stdio transport therefore starts with no acting account at all.

Meanwhile every framework agent tool enforces its declared capability against the
supplied account:

- `packages/ai-tools/src/AbstractAgentTool.php:216–226` —
  `requireCapability()` calls `$account->hasPermission($capability)` and, on a
  miss, returns `AgentToolResult::error(..., summary: 'forbidden')`.
- The five Bimaaji adapters gate on two capabilities:
  `bimaaji.read` (`IntrospectGraphTool.php:27`, `IntrospectSectionTool.php:28`,
  `SearchSpecsTool.php:29`) and `bimaaji.mutate`
  (`ProposeMutationTool.php:29`, `GeneratePatchTool.php:41`).

Net effect: stand up an stdio server without settling identity and every single
tool call comes back `forbidden`. **This ADR exists to decide who the principal
is, rather than letting the first implementation decide it under pressure.**

### C-2 — `DevAdminAccount` is the wrong answer, and it is reachable from `cli`

`Waaseyaa\User\DevAdminAccount` is a wildcard principal:

- `packages/user/src/DevAdminAccount.php:40` — `id()` returns `PHP_INT_MAX`
  (`AnonymousUser` uses `0`, `packages/user/src/AnonymousUser.php:27`).
- `packages/user/src/DevAdminAccount.php:43–46` — `hasPermission()` returns
  `true` for every string.
- `packages/user/src/DevAdminAccount.php:48–52` — `getRoles()` returns
  `['administrator']`.
- `packages/user/src/DevAdminAccount.php:59–62` — `claimsGeneration()` returns the
  constant `'dev-admin'`, so it never advances when policy changes.

Critically, its own SAPI guard is **wider** than the kernel's:
`packages/user/src/DevAdminAccount.php:26` lists
`['cli-server', 'cli', 'frankenphp']`. `cli` is present. Nothing in the class
prevents an stdio bootstrap from simply constructing one — the only thing standing
between the local plane and a wildcard administrator is a design decision, which
is what this ADR records.

There is a second, independent reason to refuse it. Acceptance evidence gathered
with the dev fallback enabled is invalid on this framework: the fallback's
blanket `hasPermission()` masks protected field-read denials, so a run that
passes under it proves nothing about the access posture a real caller sees.

### C-3 — There is no read-side sovereignty guardrail

`Waaseyaa\Bimaaji\Policy\SovereigntyGuardrails` gates **mutations only**:

- `packages/bimaaji/src/Policy/SovereigntyGuardrails.php:43` —
  `validate(MutationRequest $request): MutationResult`; its three default rules
  (`:24–39`) all deny operations under `SovereigntyProfile::NorthOps`.
- Its sole production consumer is the mutation pipeline:
  `packages/bimaaji/src/Mutation/MutationValidator.php:26` (constructor) and
  `:34–35` (invocation). No read path consults it.

The active profile is the L0 enum
`packages/foundation/src/Sovereignty/SovereigntyProfile.php`
(`Local`, `SelfHosted`, `NorthOps`), and when no `SovereigntyConfigInterface` is
bound, `packages/bimaaji/src/BimaajiServiceProvider.php:362–373` falls back to
`SovereigntyProfile::Local`.

A local stdio plane that exposed `entity.read` or `content.search` over a
developer's SQLite file would stream whatever that database holds to a cloud
coding agent, under a profile that defaults to `Local` and a guardrail set that
never runs on reads. On this framework that is an OCAP and data-sovereignty
decision, not merely a security one.

### C-4 — Installing `waaseyaa/mcp` grows the HTTP surface unconditionally

`packages/mcp` registers its routes from
`packages/mcp/src/McpServiceProvider.php:303–309`:

- `packages/mcp/src/McpRouteProvider.php:60` — `/mcp`, the public read tier,
  registered when `publicEndpointEnabled()`. Its default auth strategy is
  anonymous (`McpServiceProvider.php:463–467`, `PublicAnonymousAuth`).
- `packages/mcp/src/McpRouteProvider.php:90` — `/mcp/write`. The file's own
  docblock at `:21` states it plainly: *"The authenticated write tier
  (`/mcp/write`) is always registered."* Its default credential fails closed —
  `McpServiceProvider.php:680` returns `new BearerTokenAuth([])`, so every request
  401s — but the **route exists** the moment the package is installed.

So requiring `waaseyaa/mcp` to obtain a transport is not surface-neutral. It adds
a route to the application whether or not anyone wanted one.

### C-5 — What a created project actually gets today

The transitive `waaseyaa/*` require closure of the root `waaseyaa/framework`
manifest is **63 packages** (62 direct root requires plus one pulled
transitively). It does **not** reach any of:

`ai-agent`, `ai-observability`, `mcp`, `wayfinding`, `oidc`, `messaging`,
`engagement`, `workspace`, `testing`.

(The remaining unreached directories are the three metapackages `core`/`cms`/`full`
and the `genealogy` distribution extension, which are opt-in by design.)

The consequence is specific: a skeleton project **does** get `waaseyaa/bimaaji`,
but it gets neither the five `#[AsAgentTool]` adapters that expose Bimaaji
(they live in `packages/ai-agent/src/Tool/Bimaaji/`) nor any transport. There is
no path from a fresh install to a working local agent plane without a new,
explicitly required bundle.

`waaseyaa/testing` is a strong candidate member of that bundle: 13 of its 14
`src/` classes carry class-level `@api` (the sole exception is
`packages/testing/src/Factory/EntityTypeFixtureValues.php`), and it declares
`phpunit/phpunit` under `require-dev` only, so its production autoload is clean.

### C-6 — Package-name availability

Checked **2026-08-29**: no GitHub repository existed at the target split path for
`ai-development`, and Packagist returned HTTP 404 for `waaseyaa/ai-development`.
The name was unclaimed on that date. This ADR records the observation and its
date; it is not a standing guarantee, and #2655 re-confirms at reservation time.

## Decision

### D-1 — The bundle is `waaseyaa/ai-development`, a `require-dev` metapackage

The local development plane is distributed as a new package
**`waaseyaa/ai-development`**.

1. It MUST declare `"type": "metapackage"` and own no `src/`, no resources, and
   no service provider. Declaring the type is also how it earns the
   layer-check exemption without editing a hardcoded list:
   `bin/check-package-layers:851` skips any manifest whose `type` is
   `metapackage`, before the hardcoded `$metapackages` map at `:154` is even
   consulted. The existing three metapackages set the precedent
   (`packages/core/composer.json:4`).
2. It MUST NOT appear in the `require` block of `waaseyaa/framework`,
   `waaseyaa/core`, `waaseyaa/cms`, or `waaseyaa/full`. Consumers opt in by name.
3. The skeleton MUST install it under `require-dev` only, and a
   `composer install --no-dev` MUST remove it and everything it pulls.
4. It MUST NOT require `waaseyaa/mcp`. Per C-4 that would register `/mcp/write`
   in every application that installed a development tool. The transport for
   the local plane is the stdio server of #2659, reached through the
   transport-neutral contracts of #2657.

### D-2 — `waaseyaa/ai-agent` and `waaseyaa/testing` are **in**; the HTTP MCP package is **out**

`waaseyaa/ai-agent` MUST be a direct dependency of `waaseyaa/ai-development`. It
is where the three default-profile tools already live
(`packages/ai-agent/src/Tool/Bimaaji/`) and, per D-3.0, where
`LocalOperatorPrincipal` will live. It is absent from every production require
closure today, and D-10 requires that to be gated rather than assumed.

`waaseyaa/testing` MUST be a direct dependency of `waaseyaa/ai-development` once
the graph is populated. Rationale: it is not reachable from the framework closure
(C-5), its entire meaningful surface is already `@api` consumer surface, and the
development plane's whole purpose is to make an application legible and testable
to a developer's agent. It carries no HTTP surface and no runtime cost in a
`--no-dev` install.

`waaseyaa/mcp` MUST NOT be a dependency, for the reason in D-1.4.

### D-3 (normative) — Identity: `LocalOperatorPrincipal`, never persisted

The local plane's acting identity is a new `LocalOperatorPrincipal` implementing
`Waaseyaa\Access\AuthorizationPrincipalInterface`
(`packages/access/src/AuthorizationPrincipalInterface.php:14–18`). Its shape is
modelled directly on the existing non-persisted system principal
`Waaseyaa\Migration\Account\MigrationSystemAccount`, which solved the same
problem for batch imports.

**D-3.0 — where the class lives, and why it is not `waaseyaa/ai-tools`.**
`waaseyaa/ai-development` is a metapackage and owns no code (D-1.1), so the class
must live in a code-bearing package. #2658's title prefix implies
`waaseyaa/ai-tools`. That package is the wrong home, and this ADR overrides the
implication.

`waaseyaa/ai-tools` is a production `require` of **both** the root
`waaseyaa/framework` manifest (`"waaseyaa/ai-tools": "self.version"`) and
`packages/full/composer.json` (`"waaseyaa/ai-tools": "^0.1.0-alpha.299"`). Homing
the principal there would ship the most security-sensitive class in this design
into every production `full` install and every skeleton install, by a route
`composer install --no-dev` does not touch — silently contradicting D-1.3.

The class MUST therefore live in a package absent from the production `require`
closure of `waaseyaa/framework`, `waaseyaa/core`, `waaseyaa/cms`, and
`waaseyaa/full`. **`waaseyaa/ai-agent` satisfies that today** and is the
designated home: it is `require-dev` in the root manifest and `suggest`-only in
`packages/full/composer.json`, it is absent from the framework's 63-package
require closure (C-5), it already holds the five `#[AsAgentTool]` Bimaaji
adapters the default profile needs (`packages/ai-agent/src/Tool/Bimaaji/`) so the
dev metapackage must pull it in regardless, and it sits at the same layer as
`ai-tools` (both Layer 5, `bin/check-package-layers:129,131`), so the choice
costs nothing in layer terms. The exact namespace within it is #2658's to fix.

**The invariant is normative; the package name is merely today's satisfying
answer.** If `waaseyaa/ai-agent` ever enters a production require closure, the
principal MUST move rather than the invariant bending — and per D-10 that
invariant MUST be bound to a gate rather than left to review attention.

**D-3.0a — packaging is not the containment, and this ADR does not pretend it
is.** Even with the class outside every production closure, packaging is the
weaker of the two controls: a consumer may require `waaseyaa/ai-agent` directly,
and a future dependency edit could pull it into a production closure without
anyone noticing the security consequence. **R-6 is the actual containment** —
construction refusing outside a development runtime and outside the validated
local stdio transport. R-6 MUST therefore be proven by test, not assumed: #2658
MUST carry a test that attempts to construct the principal in a
production-shaped runtime and asserts refusal. A design that depends on the class
being *absent*, rather than on its *refusing to exist*, is one dependency edit
away from being wrong.

**Identity storage — the decision is: none.**

1. `LocalOperatorPrincipal` MUST NOT be persisted. It MUST NOT have a `users`
   row, a session record, a token record, an OAuth client, or any other durable
   identity artefact. It is constructed per-process by the validated local stdio
   bootstrap and dies with that process.
2. `id()` MUST return a **string** sentinel, not an integer.
   `AccountInterface::id()` already permits `int|string`
   (`packages/access/src/AccountInterface.php:15`), and
   `MigrationSystemAccount` establishes the convention with
   `private const string ID = 'migration:system';`
   (`packages/migration/src/Account/MigrationSystemAccount.php:92,101`). A string
   sentinel cannot collide with an auto-increment uid, cannot be confused with
   `AnonymousUser`'s `0`, and cannot be confused with `DevAdminAccount`'s
   `PHP_INT_MAX`.
3. The sentinel MUST be a fixed literal. It MUST NOT embed the OS username, the
   home directory, the hostname, the absolute project path, or any other
   machine-identifying value.
4. `hasPermission()` MUST be a strict membership test against an explicitly
   injected capability list, exactly as
   `MigrationSystemAccount::hasPermission()`
   (`packages/migration/src/Account/MigrationSystemAccount.php:106`) does. It
   MUST NOT return `true` unconditionally for any input, and MUST NOT pattern-
   match, prefix-match, or wildcard.
5. `getRoles()` MUST NOT include `administrator` or any role that an
   `AccessPolicyInterface` in the framework treats as a blanket grant.
6. `tenantId()` and `communityId()` MUST both return `null` by default. The local
   operator is unbound to any tenant or community unless an application binds one
   explicitly through policy or scope configuration.

### D-4 (normative) — Claims generation advances with policy

`claimsGeneration()` MUST be derived from the principal's effective authority, so
that any change to it produces a different value. Concretely:

1. It MUST be a deterministic digest over, at minimum, the granted capability
   list and the active read-side sovereignty policy (D-8). The established
   pattern is `MigrationSystemAccount::claimsGeneration()`
   (`packages/migration/src/Account/MigrationSystemAccount.php:122–125`), which
   hashes its permission list.
2. It MUST NOT be a constant literal. `DevAdminAccount` returns the fixed string
   `'dev-admin'` (`packages/user/src/DevAdminAccount.php:61`); that is precisely
   the property this decision rejects, because `claimsGeneration()` is a cache and
   authorization dimension — it is consumed by
   `packages/cache/src/ProtectedCacheDimensions.php:12`,
   `packages/entity-storage/src/SqlEntityQuery.php:1141`,
   `packages/api/src/Controller/ContentSearchController.php:188`, and the queue
   envelope at `packages/queue/src/Envelope/QueueEnvelopeV1.php:21`.

   A constant generation means cache entries computed under one authority are
   reused under a different one. The dangerous direction is **narrowing**: an
   operator revokes a capability, or tightens the read-side sovereignty policy,
   and the principal keeps being served entries computed while it still held the
   broader authority — data the now-narrower principal must not see. (The reverse
   case, a widened grant reading an entry built under the narrower one, is merely
   an undergrant: the principal sees less than it is entitled to, which is
   inconvenient, not a disclosure.) Requiring the digest to move on every policy
   change is what makes revocation take effect at the cache boundary rather than
   at the next eviction.
3. It MUST NOT incorporate any machine-identifying value, for the same reason as
   D-3.3.

### D-5 (normative) — Audit correlation

Every tool dispatch on the local plane MUST be recorded through the existing
strict ledger port `Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface`
(reserve-before-side-effect, `packages/foundation/src/Audit/`), using
`StrictAuditReservation`
(`packages/foundation/src/Audit/StrictAuditReservation.php:32–38`).

D-5 spans three work units, because no single one can discharge it: the
principal's audit *identity* exists before any dispatch path does, and the
enforcement has nowhere to live until the bridge and transport exist. The
clauses below are grouped by owner so the split is not left to inference.

**D-5.A — the ledger must be real (owner: #2657, enforced again by #2659).**

1. A `NullStrictAuditLedger` MUST NOT satisfy this decision.
   `Waaseyaa\Foundation\Audit\NullStrictAuditLedger` implements the same
   interface and records nothing, so "records through
   `StrictAuditLedgerInterface`" is otherwise satisfiable by a no-op. The local
   plane MUST refuse to construct, or refuse to dispatch, when the resolved
   ledger is absent or is a `NullStrictAuditLedger`.
2. The precedent is exact and already in this codebase: `McpEndpoint` refuses
   construction when durable audit is on and the ledger is absent or null
   (`packages/mcp/src/McpEndpoint.php:130–146`), on the stated grounds that such
   a surface "LOOKS durably audited and records nothing" and that the
   construction *is* the wiring error. The local plane MUST mirror that refusal
   rather than reinvent it.

**D-5.B — reserve/finalize around dispatch (owner: #2657).**

3. Every tool dispatch MUST be wrapped in reserve-before-side-effect: the
   reservation durable *before* the tool runs, the outcome finalized after. A
   terminal refusal that never reaches execution MUST use the single-shot
   `record()` rather than a dangling reservation.
4. `safeArguments` MUST come from the tool's own redaction transform
   (`argumentsForAudit()`), never from raw JSON-RPC params.

**D-5.C — transport naming and correlation (owner: #2659).**

5. `surface` MUST be a dedicated constant naming the local stdio plane. It MUST
   NOT reuse `mcp.write` or any HTTP surface identifier — the audit trail must
   distinguish a local developer session from a network caller on inspection.
6. `correlationId` MUST be per-request and MUST join every record produced by
   one tool call.

**D-5.D — the principal's audit identity (owner: #2658).**

7. `actorUid` MUST be `null`. That field is a three-state `?int` whose documented
   semantics are "id N, `0` only when the actor IS anonymous, or `null` for no
   known persisted principal"
   (`packages/foundation/src/Audit/StrictAuditReservation.php:26–28`). The local
   operator has no persisted account, and coercing its string sentinel to an int
   would yield `0` — silently attributing the session to `AnonymousUser`. The
   principal's identity travels in `metadata` instead, as the sentinel string
   plus the current claims generation.
8. No audit record, log line, or error envelope produced by the local plane may
   contain the OS username, home directory, hostname, or absolute project path.

### D-6 (normative) — Refusal conditions

`LocalOperatorPrincipal` MUST be constructible **only** by the validated local
stdio transport bootstrap. Every one of the following MUST refuse it:

| # | Boundary | Required behaviour |
|---|---|---|
| R-1 | HTTP authentication | No `McpAuthInterface`, `WriteTierAuthInterface`, `BearerAuthMiddleware`, or `SessionMiddleware` path may ever yield a `LocalOperatorPrincipal`. An HTTP request MUST NOT be able to produce one by any header, cookie, or parameter. |
| R-2 | Token validation | No bearer token, API key, JWT, OAuth access token, or durable token record may resolve to it. It has no credential, because per D-3.1 it has no persisted identity to bind a credential to. |
| R-3 | Persistent account resolution | `UserRepository` and every account-loading path MUST NOT return it. Its string sentinel MUST NOT be accepted as a uid lookup key. |
| R-4 | Entity ownership and attribution | It MUST NOT be installed into the kernel `AccountContext`. `EntityRepository::resolveActor()` casts the ambient account's `id()` to `int` (`packages/entity-storage/src/EntityRepository.php:364–374`); `(int)` of a string sentinel is `0`, which is `AnonymousUser`. `MigrationSystemAccount` carries this exact warning in its docblock (`packages/migration/src/Account/MigrationSystemAccount.php:67–79`) and the local principal inherits it. It MUST NOT appear as `revision_author`, entity owner, or authored-by on any stored row. |
| R-5 | Serialization | It MUST NOT be serializable into a queue envelope, session payload, cache key body, or any artefact that outlives the stdio process. |
| R-6 | Non-development runtime | Construction MUST refuse outside a development environment, and MUST refuse when the acting process is not the local stdio transport. |

Refusal MUST be an explicit, loud failure — a thrown exception or a structured
refusal envelope. A silent downgrade to anonymous is not an acceptable
implementation of any row above.

### D-7 (normative) — Default tool profile: an explicit tool-ID allowlist

**The default profile is a closed list of tool IDs, not a capability grant.**
The distinction is load-bearing and this ADR settles it in the allowlist's
favour.

`AbstractAgentTool::requireCapability()` evaluates a capability *string* against
the account (`packages/ai-tools/src/AbstractAgentTool.php:216–226`); it consults
no tool roster. So "grant `bimaaji.read`" is an open set: any class added later
carrying `#[AsAgentTool(capability: 'bimaaji.read')]` joins the default profile
the moment it is discovered, with no edit to this ADR, no review of what it
reads, and no signal to the operator. A least-privilege default that grows by
attribute is not least-privilege — it is whatever the last merge made it.

1. The default profile MUST be defined as an explicit allowlist of tool **IDs**
   (the `name:` argument of `#[AsAgentTool]`). Membership is by exact string
   match against that list.
2. Capability checks MUST remain in force underneath it. The allowlist narrows;
   it never widens. A tool on the allowlist whose capability the principal does
   not hold MUST still be refused by `requireCapability()`. The two controls are
   layered, and neither is a substitute for the other.
3. The allowlist MUST be enforced by an **executable exact-membership gate**:
   adding a tool that would enter the default profile MUST fail CI until the
   recorded roster is deliberately updated in the same change. Silence is not
   acceptable as the response to a widened default. The repository already has
   the shape to copy — the S1 recorded-roster gates compare a live scan against
   a committed `support/*.json` roster, fail on any divergence, and offer
   `--write-roster` for the deliberate update (e.g.
   `bin/check-s1-schema-authority` against
   `support/s1-schema-authority-roster.json`).

The allowlist's initial membership is exactly these three tools, all read-only
and all structural:

| Tool ID | Capability | What it reads |
|---|---|---|
| `bimaaji_introspect_graph` (`packages/ai-agent/src/Tool/Bimaaji/IntrospectGraphTool.php:26–27`) | `bimaaji.read` | The composed application graph |
| `bimaaji_introspect_section` (`.../IntrospectSectionTool.php:27–28`) | `bimaaji.read` | One graph section |
| `bimaaji_search_specs` (`.../SearchSpecsTool.php:28–29`) | `bimaaji.read` | Markdown spec bodies |

Today `bimaaji.read` happens to admit exactly these three. That coincidence is
the reason the allowlist must exist now, while it is free, rather than after the
fourth `bimaaji.read` tool has already shipped inside the default.

The graph sections are schema, not rows. `EntityIntrospectionProvider` iterates
`EntityTypeManagerInterface::getDefinitions()` and emits labels, classes, keys,
field definitions, group, revisionable and translatable flags
(`packages/bimaaji/src/Introspection/Entity/EntityIntrospectionProvider.php:26–37`).
It touches no storage and returns no stored value. The other five default
providers — `Admin`, `JsonApi`, `PublicSurface`, `Routing`, `Sovereignty` — are
structural in the same way.

**The third tool is inert until #2661 and #2662 land, and the ADR does not
pretend otherwise.** `bimaaji_search_specs` returns nothing in a real consumer
install: `BimaajiServiceProvider::resolveSpecsDirectory()` returns `null` unless
`bimaaji.specs_directory` is explicitly configured
(`packages/bimaaji/src/BimaajiServiceProvider.php:383–390`), and `docs/specs/`
ships in no package. Making it answer is **#2661** (lifecycle metadata and a
sanitized versioned corpus) and **#2662** (cited, version-matched FTS5
documentation search). It is **not** #2656, which packages canonical Agent
Skills — a different artefact that does not make this tool return anything.

#2661 is a correctness precondition, not only an availability one: its acceptance
requires the default index to carry live material only, with superseded and
historical states behind an explicit labelled filter. Until it lands, a search
over the raw directory would surface retired designs as if current —
`docs/specs/entity-storage-two-axis.md` is Superseded (M-004 `vid` model, retired
alpha.196) while `revision-system-unified.md` is live canonical. Those documents
do carry supersession banners in prose, but the tool's result shape is *matching
file, line number, nearest `##`/`###` heading, and a snippet*
(`packages/ai-agent/src/Tool/Bimaaji/SearchSpecsTool.php:46`), which does not
carry a top-of-file status banner to the caller. The allowlist therefore ships
three members of which two answer today, and that is a stated dependency rather
than a defect in this decision. The tool stays on the allowlist rather than being
removed and re-added later: its membership is already reviewed, and D-7.3's gate
means re-adding it would otherwise read as a deliberate widening of the default.

**Everything else is opt-in and off by default**, and this list is exhaustive of
what the default profile withholds:

- Content and entity **values** — `tool.entity.read`, `tool.entity.list`,
  `tool.entity.search`, `tool.content.search`.
- **Relationships** — `tool.relationship.traverse`.
- **Vectors** — `tool.vector.search`.
- **User-bearing logs and diagnostics** — any surface that can return a request
  log, session record, audit row, or telescope entry containing user data.
- **Every mutation** — `tool.entity.create`, `tool.entity.update`,
  `tool.entity.delete`, `tool.entity.rollback`,
  `tool.entity.set_current_revision`, `bimaaji.mutate`, and the Wayfinding
  `present guided content` capability — a single capability covering both trail
  reads and trail writes (`packages/ai-agent/src/Tool/Wayfinding/`), so it cannot
  be granted for reading alone and is withheld entirely.

Opting in MUST be an explicit act by the application operator, recorded in
configuration, and MUST advance `claimsGeneration()` per D-4.

### D-8 (normative) — Read-side sovereignty policy

Because the existing guardrails are mutation-only (C-3), the local plane MUST NOT
rely on them for reads. Instead:

1. Enabling any content-bearing read capability from the D-7 opt-in list MUST be
   evaluated against the active `SovereigntyProfile`
   (`packages/foundation/src/Sovereignty/SovereigntyProfile.php`), resolved the
   same way `BimaajiServiceProvider::resolveSovereigntyProfile()` resolves it
   (`packages/bimaaji/src/BimaajiServiceProvider.php:362–373`).
2. The evaluation MUST fail closed. An unresolvable or unbound profile MUST deny
   the content-bearing capability, not default it open. The existing fallback to
   `SovereigntyProfile::Local` is a *default for structural introspection*; it
   MUST NOT be read as consent to ship content values off the machine.
3. Read-side sovereignty evaluation is a **new** surface. It is not the
   `SovereigntyGuardrails` class, which takes a `MutationRequest`
   (`packages/bimaaji/src/Policy/SovereigntyGuardrails.php:43`) and cannot express
   a read decision. Whether it becomes a sibling policy in `bimaaji`, a
   foundation-level port, or part of the #2657 registry bridge is an open
   question (Q-2 below); that it must exist before any content-bearing capability
   is enabled is not.

### D-9 (normative) — HTTP rejection boundary

1. Installing `waaseyaa/ai-development` MUST register **zero** HTTP routes. The
   proof obligation is a provider test asserting no `/mcp` route exists after
   installation (#2657's acceptance already states this).
2. The local plane MUST NOT enable, un-gate, or reconfigure `/mcp` or
   `/mcp/write`. The existing defaults — anonymous public read
   (`packages/mcp/src/McpServiceProvider.php:463–467`) and a fail-closed empty
   write credential (`:680`) — are untouched by this ADR.
3. The transport-neutral contracts extracted by #2657 MUST NOT depend on HTTP
   request or response classes, so that the stdio adapter can consume them
   without dragging a route registrar behind it.

### D-10 — Governed gate obligations for the new package

`waaseyaa/ai-development` MUST clear the same gates every first-party package
clears. Recorded here so #2655 has an explicit checklist:

- `bin/check-composer-policy` **CP007** — package-local path repositories and
  internal `require`/`require-dev` entries must correspond in both directions
  (rule ids at `bin/check-composer-policy:20–25`).
- `bin/check-composer-policy` **CP-NEW** — every internal `waaseyaa/*` constraint
  must equal `^<checked-out VERSION>` (currently `^0.1.0-alpha.299`, per the
  tracked `VERSION` file). `bin/sync-internal-versions` advances it at each
  release cut.
- **CP002** (`@dev` forbidden), **CP003** (no wildcard internal constraints), and
  **CP006** (`self.version` only in the root manifest) apply unchanged.
- `bin/check-package-layers` — satisfied by the `type: metapackage` skip at
  `bin/check-package-layers:851`; no layer-table row is required.
- The `split.yml` mirror matrix — a new entry alongside the existing
  `packages/cms` / `packages/core` / `packages/full` rows
  (`.github/workflows/split.yml:121–123`).
- `tests/Integration/Phase23/MetapackageSmokeTest.php` — autoloadability.
- `docs/public-surface-map.php` — a metapackage exports no symbols, so it adds no
  entries; the map changes only when #2658 introduces `LocalOperatorPrincipal`,
  which is a consumer-facing extension point and MUST be mapped then.
- `bin/check-pr-preflight` and the recorded preflight roster
  (`tools/preflight-gates.json`).
- **A gate binding D-3.0's closure invariant.** The package homing
  `LocalOperatorPrincipal` MUST NOT appear in the production `require` closure of
  `waaseyaa/framework`, `waaseyaa/core`, `waaseyaa/cms`, or `waaseyaa/full`. This
  is the one D-10 obligation that is not already covered by an existing checker:
  `bin/check-composer-policy` and `bin/check-package-layers` are the natural
  hosts, and #2655 chooses. Without it the invariant survives only on review
  attention, and a routine dependency edit is enough to void it silently — the
  edit would look like adding a require, not like relocating a security-sensitive
  class into production.

## Consequences

- A developer who wants the local agent plane must ask for it by name. Nothing in
  `core`, `cms`, `full`, or `framework` starts pulling it in, and nothing about a
  production install changes.
- The first stdio session is deliberately narrow: an agent can read the
  application's shape, and — once #2661 and #2662 land — its documentation, and
  nothing else. That is a smaller opening offer than "it just works with your
  content", and it is the point.
- D-3.0 costs something real: the principal cannot live beside the agent-tool
  base class it is designed for, because `waaseyaa/ai-tools` is production-present
  in `framework` and `full`. `waaseyaa/ai-agent` is the home instead. That is a
  packaging constraint a future dependency edit can quietly break, which is why
  D-10 requires it to be gated and D-3.0a requires R-6 — the runtime refusal — to
  be proven by test rather than inferred from where the file happens to sit.
- D-3.2's string sentinel means the local principal is structurally incapable of
  being mistaken for a user row, and structurally incapable of being the ambient
  acting account (R-4). Any code that assumes an int uid will fail loudly rather
  than binding to uid `0`.
- D-4 means a revocation takes effect at the cache boundary: narrowing the local
  grant, or tightening the read policy, moves the generation and so retires every
  entry computed under the broader authority, instead of continuing to serve the
  now-narrower principal data it is no longer entitled to.
- D-7.3 adds a recorded roster and a gate that the local plane did not previously
  need. The cost is one more artefact to regenerate deliberately; the thing it
  buys is that a future `bimaaji.read` tool cannot join the default profile by
  merge alone. Given the default profile is the whole of this design's
  least-privilege claim, that trade is worth making now rather than after the
  first silent widening.
- The read-side sovereignty surface (D-8) is new work that did not previously
  exist. It is the price of ever enabling `entity.read` on this plane, and it is
  now a stated precondition rather than something discovered late.
- `waaseyaa/mcp` and its two routes remain exactly as they are. This program adds
  no network surface.

## Decision → implementation mapping

| Decision | Discharged by | Precondition |
|---|---|---|
| D-1, D-2, D-10 | #2655 — register the empty `waaseyaa/ai-development` metapackage through every release gate | This ADR accepted |
| D-3.0 (home package) | #2658 places the class in `waaseyaa/ai-agent`; #2655 binds the closure invariant to a gate (D-10) | This ADR accepted |
| D-3.0a (R-6 proven by test) | #2658 — a test asserting refusal in a production-shaped runtime | This ADR accepted |
| D-3, D-4, D-6 | #2658 — implement `LocalOperatorPrincipal` (identity, claims generation, refusal conditions) | **MUST NOT** begin before this ADR is accepted |
| D-5.A (real ledger, no `NullStrictAuditLedger`) | #2657 defines the refusal at the bridge; #2659 enforces it again at transport construction | This ADR accepted |
| D-5.B (reserve/finalize around dispatch, redacted arguments) | #2657 — the bridge owns dispatch, so it owns the wrapper. **Not #2658**: no dispatch path exists until the bridge does. | This ADR accepted |
| D-5.C (stdio `surface` constant, per-request correlation id) | #2659 — the transport owns its own identity and request boundary | **MUST NOT** begin before #2657's design is accepted |
| D-5.D (principal audit identity: `actorUid: null`, sentinel + generation in `metadata`, no machine-identifying values) | #2658 | **MUST NOT** begin before this ADR is accepted |
| D-7.1, D-7.2 (tool-ID allowlist, layered capability checks) | #2658 — defines the allowlist and its initial three members | **MUST NOT** begin before this ADR is accepted |
| D-7.3 (executable exact-membership gate) | #2658 ships the gate with the allowlist; the roster lives beside the S1 rosters in `support/` | This ADR accepted |
| D-7's third tool answering at all | #2661 (lifecycle metadata + sanitized versioned corpus) then #2662 (cited FTS5 search). Not #2656. | Independent of this ADR; the tool is on the allowlist and inert until then |
| D-8 | #2658 for the default (no content capability, so no content read to gate); the read-side evaluation surface itself lands with the first opt-in capability, sequenced through #2657 | This ADR accepted |
| D-9.1, D-9.3 | #2657 — extract a transport-neutral tool-registry bridge without enabling HTTP | This ADR accepted |
| D-9.2 | #2659 — conformant local stdio server | **MUST NOT** begin before #2657's design is accepted |

## Open questions deliberately left to #2657

- **Q-1 — Where the transport-neutral contracts live.** This ADR requires that
  they exist and that they not depend on HTTP request/response classes (D-9.3).
  It does not decide whether they land in `ai-tools`, in a new package, or as a
  narrowed port in `foundation`. Whichever is chosen, D-3.0's closure test
  applies to anything that must not reach a production install; note that
  `ai-tools` and `foundation` are both production-present, so siting
  developer-only types in either would repeat the problem D-3.0 fixes.
- **Q-2 — Where read-side sovereignty evaluation lives.** D-8 fixes the
  obligation and the fail-closed semantics. It does not fix whether the surface
  is a `bimaaji` sibling to `SovereigntyGuardrails`, a foundation-level port, or
  a concern of the registry bridge itself.
- **Q-3 — How capability scoping is expressed at the bridge.** `waaseyaa/mcp`
  already has `packages/mcp/src/CapabilityScopedToolRegistry.php`; whether the
  local plane reuses that
  type, or the bridge introduces a transport-neutral equivalent that both
  adapters consume, is #2657's call.
- **Q-4 — Whether the audit `surface` constant is owned by the bridge or the
  stdio adapter.** D-5.1 fixes that it must be dedicated and must not reuse an
  HTTP identifier; ownership is a bridge-design detail.

## Non-goals

This ADR decides nothing about, and explicitly declines:

- Any implementation. No PHP class, no package manifest, no CLI command, no
  transport is introduced here.
- Remote or hosted MCP, OAuth for MCP, and MCP Registry publication. Out of scope
  for the whole S5 program per #2653.
- Changing `waaseyaa/mcp`'s existing `/mcp` and `/mcp/write` behaviour, defaults,
  or auth strategies.
- Changing `DevAdminAccount`, `AnonymousUser`, or the `HttpKernel` dev-fallback
  gates. C-2 explains why the local plane must not use the dev fallback; it does
  not propose altering it.
- Vector RAG (#1606), HTTP OAuth (#1640), media mutation (#1639 / #2517), and
  Admin AI observability (#1415) — deferred by #2653 until S5 field evidence
  justifies promotion.
- Any authority to merge, tag, release, split, deploy, or mutate production.

## Related

- `docs/specs/mcp-endpoint.md` — the HTTP MCP surface this ADR agrees not to grow
- `docs/specs/access-control.md` — account, policy, and dev-fallback semantics
- `docs/specs/ocap-audit-log.md` — the audit substrate D-5 records into
- `docs/adr/019-mcp-tool-access-enforcement.md` — the decision that agent tools
  enforce against the initiator account, which is what makes C-1 true
- `docs/adr/004-framework-package-collapse.md` — the metapackage-menu boundary
  D-1 extends
- `packages/migration/src/Account/MigrationSystemAccount.php` — the working
  precedent for a least-privilege, never-persisted, string-sentinel principal
