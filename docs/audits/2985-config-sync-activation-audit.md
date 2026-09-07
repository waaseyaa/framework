# #2985 — configuration sync and activation audit

Audit lane of **#2985**, anchored to open issues **#2432** and **#2433**.
Read-only: no runtime change, no class removal, no public-surface
reclassification, no test suites run.

- **Pinned source SHA:** `1000cd9af73148b008cdea0c517fd28acaf0fe5d` (`origin/main`)

## Headline

**No confirmed authorization defect. Both #2432 and #2433 are correctly
labelled `status:needs-design`, and neither is an authorization hole.**

The destructive surfaces are **fail-closed by construction**: the authorization
seams exist as first-class public interfaces, the shipped defaults refuse, and
the refusal happens *before* any database mutation. What is missing is a
designed permitting policy — which is what both issues say. This audit lowers
the alarm on both while confirming their framing.

One genuine gap is recorded that neither issue covers: **no `config.audit`
record was found for rollback or candidate sweep** (§5 F1).

### Scope of every claim in this report

This is a **bounded static audit, not a complete security assessment.** Its
negative claims — "CLI-only", "no audit record", "entirely unwired", "no
production caller" — mean *"no such path was found by the searches recorded
here"*, and each should be read with that qualifier until independently
reviewed. They are grep-and-read results over first-party `packages/*/src` at
one commit. They do not cover consumer applications, published package versions
that differ from this tree, dynamic dispatch, container-bound closures resolved
by string, reflection, or configuration-driven wiring. A reviewer should treat
them as strong leads that shift where to look, not as proofs of absence.

The **design dependencies** the report identifies — that import and rollback
must be designed together (F7), that auditing should land before a permitting
policy (F1), that sweep cannot strand a rollback (F3) — do not depend on the
negatives being exhaustive, and stand on the positive evidence cited.

## 1. Scope and coverage matrix

| Area | Reviewed | Depth | Notes |
|---|---|---|---|
| Rollback seam | ✅ | Full: semantics, refusal, sequencing, recovery | |
| Candidate sweep seam | ✅ | Full, incl. sweep-vs-rollback interaction | |
| Destructive import authorization | ✅ | Tombstone model + default authorizer | |
| Default authorizer wiring | ✅ | Verified in production provider | |
| Activation CAS / sweep fence | ✅ | Verified | |
| Config-mutation reachability | ✅ | Exhaustive: routes, JSON:API, admin surface, MCP, agent tools | |
| `config.audit` emission | ✅ | All production emitters | |
| Existing gates and specs | ✅ | 12 architecture tests, 2 bin gates, 4 specs, 1 ADR | |
| `config:import` internals (orphan set, transactionality, dry-run) | ✅ | Full | |
| Activation authority internals (ownership, drift, manifest, schema) | ✅ | Full | |
| **Secret custody / signing key lifecycle (CFG-04)** | ❌ Not reviewed | — | Adjacent; own change record |
| **#2545 cloned-database authority** | ❌ Not reviewed | — | Open beta-blocker, adjacent |
| Test suites | ❌ Not run | — | Static audit per scope |

All five research lanes returned and are incorporated.

## 2. Trusted-operator versus remotely-reachable — the distinction that sets severity

A destructive configuration operation invoked by an operator with shell access
is ordinary administration. The absence of an entity-access check on it is
**not** a defect — configuration is not entity data, and the CLI has no request
principal by design (confirmed in the authorization audit lane: `ConsoleKernel`
never establishes `_account`).

What would matter is a config mutation reachable from HTTP, JSON:API, GraphQL,
MCP or an agent tool. **None was found** (§4), though that search is partial.

## 3. Confirmed: the destructive surfaces are fail-closed by construction

### Default wiring
`packages/entity-storage/src/Config/ConfigurationStorageServiceProvider.php:72-77`:

```php
$authorizer        = resolveOptional(ConfigurationActivationAuthorizerInterface::class)
                     ?? new VerifiedNonDestructiveConfigurationActivationAuthorizer();
$rollbackValidator = resolveOptional(ConfigurationRollbackValidatorInterface::class)
                     ?? new RefusingConfigurationRollbackValidator();
$sweepAuthorizer   = resolveOptional(ConfigurationCandidateSweepAuthorizerInterface::class)
                     ?? new RefusingConfigurationCandidateSweepAuthorizer();
```

The inline comment (`:66-71`) states the intent: *"rollback and candidate sweep
keep their refusing defaults below, because their safe policies are separate
decisions. An app may still bind its own authorizer to narrow this further."*

### The refusals
- `RefusingConfigurationRollbackValidator::validate()` throws
  `DomainException('Configuration rollback is unavailable until a compatibility
  validator is bound.')`
  (`packages/config/src/Activation/RefusingConfigurationRollbackValidator.php:9-14`).
- `RefusingConfigurationCandidateSweepAuthorizer::authorize()` throws
  `DomainException('Configuration candidate maintenance requires a verified
  lease and fence.')` (`.../RefusingConfigurationCandidateSweepAuthorizer.php:9-12`).

**No permitting implementation ships.** Verified directly:
`rg -ln "implements ConfigurationRollbackValidatorInterface|implements ConfigurationCandidateSweepAuthorizerInterface" packages/ --type=php`
excluding tests returns **only the two `Refusing*` classes**. Both seams are
therefore inert unless a downstream application binds its own policy.

### Refusal precedes mutation
`rollback()` calls the validator (`DatabaseConfigurationActivator.php:298`)
*before* `activate()`, so a refused rollback never reaches the authorizer or
the database. `supersedeStagedCandidates()` calls the sweep authorizer
(`:364`) before opening a transaction or touching the fence table (`:367`).
**Refusal is not partial-mutation-then-abort; nothing is written.**

### Destructive import (#2432)
`VerifiedNonDestructiveConfigurationActivationAuthorizer` defines destructive
as `tombstones !== [] || completeReplacement` (docblock `:42`), notes that *"the
importer only produces tombstones under `--delete-orphans`"* (`:51-53`), and
throws when `$request->tombstones() !== []` (`:79`). The activator additionally
requires a content-bound tombstone for every active entry an import omits, or
it throws — so an omission cannot silently delete.

So `config:import --delete-orphans` is refused by the shipped default. #2432's
own framing is precise: the signature *proves content* but does not *authorize
deletion of omitted entries*, and the missing piece is a deletion policy
covering the signer-versus-operator boundary and removal scope.

## 4. Reachability — no non-CLI path found (bounded)

Every config write, import, activate, rollback, sweep and delete capability is
wired exclusively through `ProvidesConsoleCommandsInterface` providers.
`config:import` and `config:reset` are bound in
`packages/cli/src/Provider/ConfigCacheDbAuditServiceProvider.php:45,199-213`;
genesis activation is reached only from `InstallInitHandler::execute`
(`packages/cli/src/Handler/InstallInitHandler.php:106`) via `site:init`.

**No HTTP route, JSON:API path, GraphQL field, MCP tool or agent tool that
mutates configuration was found.** The negatives are load-bearing, and are
bounded by the searches below — they establish that no *statically visible
first-party* caller exists, not that none can exist:

- The only config-named HTTP routes are three MCP admin reads registered at
  `packages/api/src/ApiServiceProvider.php:658-678` — all `GET`, all
  `->requireRole('admin')`, and `ServerConfigReadModel` returns a redacted
  snapshot.
- JSON:API cannot reach config: config storage is never wired into
  `EntityStorageDriverInterface`/`EntityRepository`, so generic entity CRUD has
  no path to it.
- `packages/admin-surface/src` contains **no** `use Waaseyaa\Config\` import.
  Note the trap: `GenericAdminSurfaceHost.php:1013` calls `$repository->rollback(...)`,
  which is `EntityRepository::rollback()` — *entity revision* rollback in a
  different package — not `ConfigurationActivatorInterface::rollback()`. Two
  unrelated capabilities share the method name; they must not be conflated.
- `packages/ai-agent/src/Mcp/*` imports `StorageInterface`/`ConfigManagerInterface`
  but only ever calls read accessors; a search for `->write(`/`->delete(`/`->set(`/`->save(`
  across that surface returns zero matches.

**Consequence for triage:** on the evidence found, #2432 and #2433 concern
local-operator administration, and this audit surfaces no remote-reachability
basis for elevating either. That is a bounded statement: a consumer application
could expose these capabilities itself, and this audit did not review consumer
applications or published package versions.

## 5. Confirmed findings

### F1 — No `config.audit` emission was found for rollback or candidate sweep
**CONFIRMED within the searched paths. Covered by neither #2432 nor #2433.**

`ConfigAuditChannel` (`config.audit`) is emitted in production from exactly one
place: `packages/config/src/Sync/ConfigResetter.php:163,172`, for
export/import/reset. Verified: `rg -ln "ConfigAuditChannel" packages/*/src`
returns only `ConfigResetter.php` and the channel's own definition, and
`DatabaseConfigurationActivator` contains no `ConfigAudit` reference at all.

So on this evidence, when rollback or sweep eventually *is* permitted by a
bound policy, the two most consequential activation operations would mutate
state with no audit-channel record. (A subscriber attached to the logger by a
consumer application, or auditing performed by a bound policy implementation
itself, would not appear in this search.) The only durable trace is the `waaseyaa_config_activation_v2` row itself
(`previous_generation_id`, `previous_activation_sequence`,
`DatabaseConfigurationActivator.php:198-215`), written atomically inside the
same commit.

This is an **observability** gap, distinct from the authorization question, and
it should be closed *before* a permitting policy lands rather than after.

### F2 — #2433's description is stale relative to the code
**CONFIRMED.** The issue describes the candidate sweep as deleting staged rows.
The code performs an `UPDATE ... SET lifecycle_state = 'superseded'` on rows
that are `staged` and older than a caller-supplied cutoff
(`DatabaseConfigurationActivator.php:361-373`) — no row deletion. A superseded
candidate is also recoverable: `restageSupersededCandidate()` (`:429-452`)
reinstates it for an exact-hash retry of the same request id.

The issue should be corrected so its eventual design is not written against a
deletion model the code does not use.

### F3 — Sweep cannot strand a rollback
**CONFIRMED, and worth recording because it is the obvious worry.**

Two independent structural reasons:
1. Rollback eligibility is `tokenWasActivated()` (`:833-853`), which reads
   `waaseyaa_config_activation_v2` — a table the sweep never touches. Once an
   activation commits, its candidate is `committed`, outside the sweep's
   `WHERE lifecycle_state = 'staged'` predicate.
2. A sweep racing an in-flight activation is caught: the commit's own guard
   `UPDATE ... WHERE lifecycle_state = 'staged'` affects 0 rows and throws
   `ConfigurationActivationConflictException('Configuration candidate was no
   longer staged at commit.')` (`:221-229`), rolling back the transaction.

Worst case is a loud conflict requiring retry, not silent loss.

### F4 — Configuration history is unbounded
**CONFIRMED.** No deletion of `waaseyaa_config_generation_v2` /
`waaseyaa_config_entry_v2` exists anywhere in the package. Rollback targets
therefore never expire, and rollback re-activates a retained generation as a
*new* activation sequence rather than rewriting history
(`DatabaseConfigurationActivator.php:311-324`).

Recorded as a property, not a defect: it is what makes rollback safe, and it is
also unbounded growth with no pruning story. Any future pruning must not be
designed without reading F3.

### F5 — Activation and sweep have independent concurrency controls
**CONFIRMED.** Activation uses compare-and-swap on a counter
(`UPDATE waaseyaa_config_activation_counter ... WHERE last_sequence = ?`,
`:184-191`). Sweep has its own monotonic fence per `(authority_id,
lease_domain)`, rejecting a stale or replayed fence with
`ConfigurationActivationConflictException` (`:399-427`).

Recorded because the queue audit found the *opposite* shape there — an unfenced
settle path. Configuration is the better-fenced of the two subsystems.

### F6 — Two writers exist; production disables the weaker one
**CONFIRMED. This is the lane's competing-implementation answer.**

Besides the activator, `ConfigFactory::getEditable()` → `Config::save()` writes
directly through its injected `StorageInterface`
(`packages/config/src/ConfigFactory.php:49-61`). Which storage that is depends
on runtime mode: `ConfigurationAuthorityServiceProvider::mutationStorage()`
(`:373-384`) returns the **raw mutable bridge storage unwrapped** when
`RuntimePolicy::isExplicitDevelopment($config)` is true — no CAS, no authorizer,
no activation bookkeeping, no audit. Otherwise it wraps it in
`ReadOnlyActiveConfigurationStorage`, whose `write()`/`delete()`/`rename()`/
`deleteAll()` unconditionally throw (`ReadOnlyActiveConfigurationStorage.php:50-68`),
and `ConfigManager::import()` self-refuses (`ConfigManager.php:44-48`).

So single-writer discipline in production is real, but it holds **because the
second writer is disabled**, not because only one exists. That is a defensible
design — the dev bypass is narrow and explicit — but it should be stated as
such rather than assumed.

`SyncArtifactStorageAdapter` is a third writer of the **sync directory**
(authored YAML), not the active generation. Out of scope for the active-authority
invariant, and correctly so.

### F7 — Recoverability is real in storage but has no operable restore path
**CONFIRMED. Sharpens F1 and the #2432 answer.**

Config storage is **append-only**: `DatabaseConfigurationActivator` issues no
`DELETE FROM` against generation or entry tables; every mutation inserts
(`:647,661,199-200`). A `--delete-orphans` tombstone excludes a ref from the
*new* generation while the prior generation's rows remain retained. So the
bytes survive a destructive import.

But the only mechanism that would restore them is `rollback()`, whose shipped
default validator **unconditionally throws** (§3). **The data is retained and
currently unrestorable through any shipped path.** That combination — safe
storage, no operable recovery — is the most important thing for #2432/#2433's
design to address together rather than separately.

### F8 — `PackageOwnership` is declared and entirely unwired
**CONFIRMED within first-party source.**
`packages/config/src/Ownership/PackageOwnership.php:10-18` is a DTO with no
references found anywhere in `packages/` or `tests/` outside its own file. There is consequently no ownership check in the import path: import can
neither respect nor violate ownership, because nothing consults it.

### F9 — `ConfigDriftVerifier` is unwired
**CONFIRMED.** `packages/config/src/Drift/ConfigDriftVerifier.php:33-56`
performs a read-only comparison producing advisory diagnostics and never
throws. No production caller and no CLI import was found for it. It
carries `@api` (`:18`), which is why the dead-code gate accepts it as
intentional scaffolding. Functionally, drift detection is not exposed to
operators today.

### F10 — `supersedeStagedCandidates()` has no production caller
**CONFIRMED within first-party source.** The sweep is bound in
`ConfigurationStorageServiceProvider.php:86`; no resolution was found outside
`entity-storage/tests/Unit/Config/DatabaseConfigurationActivatorTest.php`. No
`config:sweep`-style CLI command exists. So #2433's sweep half is doubly inert:
refused by default *and* uninvokable.

Per the stability charter, none of F8–F10 justifies removal — no-known-callers
is evidence about this repository at this commit only, and all three are
shipped, published surface. Each is a decision to make, not a cleanup to
perform.

### F11 — Reads proceed when the authority is unavailable; only writes refuse
**CONFIRMED, intentional, worth stating.**
`ConfigurationAuthorityContext::requireActiveGenerationId()` (`:52-58`) raises
`ConfigurationAuthorityUnavailableException`, and it is called from the mutation
and capability paths but not from the read path — `ReadOnlyActiveConfigurationStorage`'s
`read`/`exists`/`listAll` (`:20-38`) never call it. This is fail-closed for
mutation, which is right. The consequence to note is diagnostic: read-only
tooling gives an operator **no signal** that the authority is unbound.

### F12 — Schema and manifest verification are mandatory and fail-closed
**CONFIRMED, and recorded as a strength.** A bundle carrying diagnostics cannot
become a manifest or activation candidate
(`ConfigSyncBundleValidationResult.php:27-28` throws). Envelope verification
checks algorithm, signature and replay-sequence monotonicity
(`ConfigManifestEnvelopeVerifier.php:14-43`). When replay state is unavailable
the provider binds `RefusingConfigImportPreflight` rather than a weaker gate,
with the comment *"a gate missing one of its checks is not a weaker gate, it is
a different one"* (`ConfigurationAuthorityServiceProvider.php:102-110`). Non-genesis
activation structurally requires a verified bundle
(`DatabaseConfigurationActivator.php:155-157`).

### F13 — Import is atomic in production; the non-atomic path is testing-only
**CONFIRMED, disproving an obvious worry.** The production path submits the
whole generation as one `ConfigurationActivationRequest` inside
`activateTransactional()` (`ConfigImporter.php:140-151,276`;
`DatabaseConfigurationActivator.php:41-63`), so a mid-import failure yields a
single failed result, not a partial commit. The per-entry loop with no rollback
(`ConfigImporter.php:153-171`) runs only under `environment === 'testing'`
(`ConfigCacheDbAuditServiceProvider.php:69`) and is unreachable in production.
`--dry-run` exists and previews created/updated/unchanged/deleted per ref
including tombstones (`ConfigImporter.php:258-273`).

## 6. Intended contracts, as documented

- **ADR-018** — Drupal-shape CMI, active/sync split, accepted 2026-05-11.
- `docs/specs/config-management.md` — canonical doctrine; S1 amendment
  (2026-08-12) introduces versioned SQLite generations and transactional
  activation (CFG-02).
- `docs/specs/s1-configuration-authority.md` (CFG-01) — bootstrap authority
  resolution and revalidation gating.
- `docs/specs/s1-configuration-schema-manifest.md` (CFG-03) — schema dialect,
  manifest/envelope, replay sequencing.
- `docs/change-records/S1-FW-CFG-04.md` — secret custody and signing lifecycle.

Already-guarded ground, which this audit does **not** propose re-proving:
`S1ConfigurationActivationContractTest`, `S1ConfigurationAuthorityContractTest`,
`S1SchemaAuthorityContractTest`, `S1SqliteTopologyContractTest` and the two
`bin/check-s1-configuration-*` gates.

## 7. Existing issue coverage

| Issue | State | Relationship | Evidence |
|---|---|---|---|
| **#2432** | OPEN p2 `needs-design` | **Confirmed as design-pending, not a hole.** Destructive import refused by the shipped default | `VerifiedNonDestructiveConfigurationActivationAuthorizer.php:42-53,79` |
| **#2433** | OPEN p2 `needs-design` | **Confirmed as design-pending**, and its sweep description is **stale** (F2) | `RefusingConfiguration*` both throw; sweep is UPDATE-to-superseded at `:361-373` |
| **#2545** | OPEN beta-blocker | Cloned-database authority contract — adjacent, not reviewed here | recon |
| #2430 | CLOSED | Verified non-destructive import for CFG-02 — the mechanism §3 relies on | — |
| #2428 | CLOSED | S1 activation phase | — |
| **F1 (audit gap)** | — | **No owning issue.** Recommend folding into #2433 rather than a new ticket, since #2433 already owns both seams | §5 F1 |

## 8. Unresolved questions and residual work

1. **What a *safe* permitting policy looks like** for rollback, sweep and
   destructive import — the substance of #2432/#2433, deliberately not designed
   here. F7 argues rollback and import should be designed together, since
   retained-but-unrestorable is the current state.
2. **Unbounded generation growth** (F4) has no pruning story; any design must
   respect F3 and F7.
3. **CFG-04 secret custody and signing-key lifecycle** was not reviewed.
4. **#2545** (cloned-database authority contract, open beta-blocker) was not
   reviewed and is adjacent to the authority-identity logic in
   `ConfigurationAuthorityContext::assertSyncPathIdentity()`.
5. **Whether F8–F10's unwired surfaces are intended scaffolding or abandoned**
   — each is shipped and `@api`-marked or publicly declared, so the answer is a
   decision, not a cleanup.

## 9. Focused acceptance criteria

Each fails for one specific defect. None should be implemented under this audit.

1. **F1:** a permitted rollback emits a `config.audit` record naming the target
   generation and the actor. *Discriminates:* fails today — the activator has no
   audit emission at all.
2. **F1:** a permitted candidate sweep emits an audit record naming what was
   superseded. *Discriminates:* same.
3. **#2432:** an import carrying tombstones is refused unless an explicitly
   bound deletion policy permits it, and the refusal writes nothing.
   *Discriminates:* passes today by refusal; must keep passing once a policy
   exists, which is the point.
4. **#2433:** a rollback to a generation that was never activated refuses with
   no partial mutation. *Discriminates:* guards the `tokenWasActivated` path.
5. **F3:** a sweep concurrent with an in-flight activation produces a conflict
   exception, not a lost activation. *Discriminates:* pins the `:221-229` guard.
6. **Reachability:** an architecture test asserting no route provider, resolver
   or agent tool references the config activation namespace. *Discriminates:*
   fails the moment config mutation gains a remote entrypoint.
7. **F6:** with `isExplicitDevelopment` false, `ConfigFactory::getEditable()->save()`
   refuses. *Discriminates:* pins that production really does disable the second
   writer, which is what single-writer discipline currently rests on.
8. **F7:** after a destructive import, the superseded generation is still
   readable *and* a bound rollback policy can restore it. *Discriminates:*
   today the first half passes and the second cannot be exercised at all.

## 10. Compatibility constraints

- `packages/config` declares 60+ public entries, including all five activation
  interfaces. Binding a permitting policy is additive; changing an interface
  signature is a semver event.
- The `Refusing*` defaults are **shipped behaviour**. Replacing a default with a
  permitting one changes observable behaviour for every consumer that has not
  bound its own policy, and is a deprecation-cycle change, not a fix.
- F4's unbounded history means any pruning feature is a data-retention change
  requiring its own decision.

## 11. Next disjoint lane — recommendation

**Session, bearer-token and CSRF lifecycle** (`packages/auth`,
`packages/user/src/Session`, `packages/oauth-provider`) — specifically token
issuance, revocation propagation, and the session-generation invalidation path
the authorization lane touched only at its edge (`AuthenticatedSession`
generation compare, revocation on next request).

Rationale: disjoint from Codex's #2847/#2848 lanes, from #2984, and from all
four delivered audits; #2816 ("bind tenant scope through session and durable
bearer authentication") and #2769 are open leads; and it is the one remaining
trust-boundary subsystem that every audit so far has touched without auditing.

Not next: `packages/access` field-read internals (#2847), `packages/cli/src/Site/`
or `packages/search` (Codex active), or any area already checkpointed.
