# Implementation Plan: Charter Amendment — Waaseyaa / Anokii Track

**Mission:** `charter-amendment-anokii-track-01KSEFE0` — see `spec.md`.
**Pattern reference:** Direct Markdown edit. No code; no tests beyond grep-checks listed in spec.md §Acceptance.
**One WP:** WP01 — edit `.kittify/charter/charter.md`.

The implementer reads the existing charter end-to-end, then makes one atomic edit inserting four blocks at the documented anchor points. The exact text of every inserted block is fully specified below so no judgment calls remain.

## §1 — Block to insert as new `## Framework vs Distribution Architecture` section

Insert immediately after the `## Branch Strategy` section closes (the last paragraph of Branch Strategy ends with "...takes no opinion on production deployment topology.") and before the `## Governance Activation` section opens.

```markdown
## Framework vs Distribution Architecture

Waaseyaa is **a framework**, not a product. Like Drupal core, Symfony, or
Rails, it is substrate that anyone can build any distribution on. It owns
the entity system, storage engine, field types, access primitives,
ingestion envelope contract, JSON:API surface, AI primitives, SSR
rendering, and the codified policy gates. Waaseyaa has its own
versioning, release cadence (semver via `scripts/release.sh`), Packagist
namespace (`waaseyaa/*`), and developer audience.

**Anokii** (Anishinaabemowin verb stem "she/he works"; working name
pending language-keeper verification before public use) is the first
opinionated distribution built on Waaseyaa. It is a separate product with
its own repo, its own release cadence, and its own end-user audience
(First Nations adopting it as a sovereign workspace, Tsen'awt-comparable
in surface area, OCAP-by-architecture differentiated). Anokii consumes
Waaseyaa packages via Composer; it contributes upstream when functionality
is generally useful; it keeps distribution-specific code (branded UX,
Indigenous-language translation pipeline, productivity-surface
configuration) in its own repo.

The Waaseyaa repo MUST NOT import from Anokii or reference
Anokii-specific features. The dependency flows one way (Anokii →
Waaseyaa). Future distributions (Minoo, Giiken, Claudriel, Nation-specific
products) follow the same pattern under the same rules: their code lives
in their own repos, their issues are tracked in their own trackers, their
release cadences are decoupled from the framework's.

The framework charter (this document) governs Waaseyaa. Each
distribution maintains its own charter governing distribution-specific
decisions (productivity surfaces, branded UX, deployment opinions,
translation governance, AODA/a11y commitments, offline-first commitments).
A change to the framework that breaks a distribution is the
distribution's problem to absorb on its own schedule; a change to a
distribution never propagates back to the framework without a separate
upstream contribution mission filed against the framework repo.
```

## §2 — Five directives to append under `## Project Directives`

Insert immediately after directive 3 ("Greenfield Removal Policy") closes (the last paragraph ends with "...the binding force of this directive comes from its text.") and before the `## Reference Index` section opens.

```markdown
4. OCAP-by-architecture is a constitutional invariant.
   Waaseyaa's audit-correct, classification-aware, access-gated data
   substrate is non-negotiable. The following MUST remain true forever:
   - Every API request resolves an authenticated account; `_account` is
     always set on the request by `SessionMiddleware` (NULL is not a
     legal value at the access-control boundary; anonymous traffic
     resolves to `AnonymousUser`).
   - Every `AgentToolInterface::execute()` receives the account and an
     `AccessChecker` instance; tool capability gating happens at the tool
     boundary AND per-record at access-time, never deferred to the
     caller.
   - Every field traversal in API serialisers (`packages/api`), MCP
     serialisers (`packages/mcp`), and SSR renderers (`packages/ssr`)
     consults `FieldAccessPolicyInterface` before exposing data;
     Neutral = accessible, Forbidden = redacted, per the open-by-default
     field-access semantics.
   - The unified OCAP audit log spans read / write / export /
     access-denied / classification-change / retention events; nothing
     that touches governed data escapes audit.
   - Per-record AI access controls (the M-A5 flagship — wired by mission
     slug `per-record-ai-access-flagship-*`) are the framework's defining
     product claim. Their wiring MUST NOT be downgraded or removed
     without a charter amendment carrying explicit Nation-level
     governance justification.

5. The two-axis entity-storage substrate (revisionable × translatable) is
   a constitutional invariant.
   The audit-trail substrate (revisions, blame, point-in-time queries,
   retention semantics) AND the Indigenous-language data substrate
   (translations, per-language field overrides, language-fallback
   resolution) share one storage shape — they are inseparable from the
   framework's purpose. Refactors MUST preserve both axes. Storage
   drivers that drop either axis are charter violations regardless of
   perceived simplification benefit. The canonical specification is
   `docs/specs/entity-storage-two-axis.md` (companion specs:
   `entity-storage-translatable-revisions.md`,
   `entity-storage-translations-v1.md`); these specs are themselves
   constitutional artifacts and changes to them require an amendment.

6. Codified policy gates are the trust substrate for the succession
   framework.
   The CI gates in the `bin/check-*` family (as of 2026-05-24:
   `bin/check-composer-policy`, `bin/check-package-layers`,
   `bin/check-dead-code`, `bin/check-getquery-bindings`,
   `bin/check-ingestion-defaults`, `bin/check-no-secrets`,
   `tools/drift-detector.sh`, and `bin/audit-require-dev-layers`) are how
   Nations and downstream maintainers verify Waaseyaa is trustworthy
   without reading every line of code. They are the procurement-legible
   surface of the framework's invariants. The succession framework
   (Tier 0 through Tier 4 — see `MAINTAINERS.md` and `SUCCESSION.md`
   once filed) depends on these gates remaining authoritative.
   - Bypassing a gate requires a `charter-exception` with a removal
     date per the Exception Policy.
   - Adding a gate is encouraged and requires no amendment; new gates
     under the `bin/check-*` pattern inherit the constitutional posture.
   - Removing a gate requires a charter amendment with explicit
     rationale citing what now provides the same guarantee (replacement
     gate, stronger type system, contract test, etc.).

7. Standalone Nuxt SPA is the architectural bet for the workspace
   surface.
   The Nuxt 3 + Vue 3 + TypeScript admin SPA (`packages/admin/`) is the
   committed workspace UI for both the framework's reference admin and
   any distribution built on Waaseyaa that does not opt out. The
   `packages/inertia` adapter remains in the tree as an OPTIONAL
   alternative protocol available to consumers but is not the framework's
   primary surface and is not where workspace-UI investment is directed.
   New workspace UI work targets Nuxt + the schema-driven admin surface
   (`useSchema`, `SchemaForm`, `SchemaField`, `SchemaView` composables /
   components). Changing this bet (to Inertia-primary, to a server-driven
   alternative, or to a dual-bet split) requires a charter amendment
   with: (a) a documented prototype comparison covering perf budgets,
   accessibility implications, and dev-velocity signal; (b) a migration
   plan for the existing 17+ admin pages and 18+ composables;
   (c) explicit author approval after AI review.

8. GPL-2.0-or-later is the framework's license commitment.
   All first-party `waaseyaa/*` packages are licensed under
   `GPL-2.0-or-later`. The `LICENSE.txt` file and the `license` field in
   every package `composer.json` MUST declare this license.
   SPDX-License-Identifier headers are required on every PHP file
   (already enforced under Quality Gates). The copyleft posture is a
   deliberate sovereignty alignment — it protects Nation adopters from
   vendor capture by preventing proprietary forks that would fragment
   the substrate. Changing the license (to Apache-2.0, dual-licensing
   selected adapter packages, or any other shift) requires a charter
   amendment with: (a) Contributor License Agreement collection from
   every contributor whose copyrightable contribution survives the
   change; (b) a documented sovereignty-impact analysis covering vendor
   capture risk under the proposed license; (c) explicit Nation-level
   stakeholder input collected through the OIATC stewards channel (or
   its successor at the time of the amendment).
```

## §3 — Block to insert as new `## Amendment History` section

Insert immediately after the `## Amendment Process` section closes (the last paragraph ends with "...as the framework matures from alpha through v1.0 and beyond.") and before the `## Exception Policy` section opens.

```markdown
## Amendment History

| Date | Amendment | Authorization |
|---|---|---|
| 2026-05-24 | Added `## Framework vs Distribution Architecture` section codifying the Waaseyaa-vs-Anokii separation. Appended directives DIR-004 (OCAP-by-architecture invariant), DIR-005 (two-axis storage invariant), DIR-006 (codified policy gates as trust substrate), DIR-007 (standalone Nuxt SPA bet), DIR-008 (GPL-2.0-or-later license commitment). | Author (Russell Jones) authorisation captured during the 2026-05-24 spec-production session via four `AskUserQuestion` answers. Authoritative trace: `kitty-specs/charter-amendment-anokii-track-01KSEFE0/spec.md` §Why this mission exists. |
```

## §4 — `Generated:` line edit

Replace exactly:

```markdown
Generated: 2026-04-27T04:26:37Z
```

With (substitute the actual amendment timestamp at edit time, in UTC, ISO-8601 format with `Z` suffix):

```markdown
Generated: 2026-04-27T04:26:37Z; Last amended: 2026-05-24T<HH:MM:SS>Z
```

## §5 — Insertion verification

Net new lines inserted: approximately 145 (the four blocks above; the `Generated:` edit is a one-line replacement, not an insertion). Implementer runs `wc -l` before and after; the delta MUST be the count of inserted lines from the four blocks (the `Generated:` replacement doesn't change line count).

## Verification gate (in lane worktree)

- `grep -c "^## Framework vs Distribution Architecture" .kittify/charter/charter.md` → `1`
- `grep -c "^## Amendment History" .kittify/charter/charter.md` → `1`
- `grep -cE "^(4|5|6|7|8)\\. " .kittify/charter/charter.md` → `5` (or higher if existing Branch Strategy beta-entry list still matches; verify the matches are under Project Directives by line range)
- `grep -c "Last amended:" .kittify/charter/charter.md` → `1`
- `git diff .kittify/charter/charter.md \| grep -c "^-"` → `2` (one is the `Generated:` line, one is the `---` git diff header). All other diff lines start with `+`.

## Reviewer focus

- Tone consistency: the inserted directives speak in the same authoritative voice as DIR-001..003 ("MUST", "MUST NOT", concrete consequences).
- Cross-reference accuracy: DIR-004 references the M-A5 mission slug exactly; DIR-005 references the two-axis specs by exact filename; DIR-006 lists current gate scripts exactly; DIR-007 references `packages/admin` and `packages/inertia` exactly; DIR-008 references the license-declaration sites exactly.
- No prose drift: the inserted text matches the plan.md blocks byte-for-byte (this is the implementer's contract; no improvements during edit).
- Amendment-History row format matches the inferred future shape (future amendments append rows in the same Date/Amendment/Authorization format).
