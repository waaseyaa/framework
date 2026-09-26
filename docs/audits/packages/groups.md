# `waaseyaa/groups` audit

- **Milestone:** assessed. Every finish criterion holds. The last, durable private custody of the security briefs (D14), was met on 2026-09-26 with a verified copy into the location the maintainer designated. Repair ready: no. Converged: not claimed.
- **Audit state:** assessed. **Remediation state:** not triaged. No slice is filed yet: #2762, #1627 and #3078 predate this audit and don't carry its scope and acceptance (#2762 needs the acceptance attached, #1627 a rescope, #3078 the group case), so they count as filed slices only once updated.
- **Base:** `a4e88e88afb1d2806b12fbc7947d32a1484e8798`, audited 2026-09-25; the v2.1 verification ran at the same base.
- **Dependency identity:** `composer.lock` SHA-256 `3b83a3ccac4d9d85278379f134a2a6a5cebdcab517542594d932a821ca2c6889` at the base; PHP 8.5.5, native Windows 11. Consumer evidence: Sheguiandah (Sheg) at `ee810a0a00efd59eba3f12694b7b3f58d1790c5d`, `composer.lock` SHA-256 `2ac135588095fd034ee3eb81697e9f903ebaa69161b8e34a9b3f3924c7f4bdb7`, groups split ref `b1a4fad0f`. Its checkout later moved to `168a251`, which changes only CI files.
- **Evidence freshness:** current at `a4e88e88a`. `packages/groups` is byte-identical to the alpha.302 release commit `3f8453630`, and neither `packages/groups` nor `packages/relationship` changed through `81a925f1c`.
- **Owner:** program `waaseyaa/framework#3118` (groups row). No package umbrella exists yet.
- **Profiles applied:** persistence-execution (membership writes, raw roster reads, schema authority); domain-contracts (membership, lifecycle and bundle invariants); kernel-runtime (provider bindings, policy discovery, reader resolution); http-ui-contracts (the generic JSON:API exposure of `group` and `group_type`); distribution (split, metapackage and `--no-dev` profiles). **Not applied:** introspection-cli (groups registers no commands; `groups:*` belong to `waaseyaa/cli`); generation-build (groups generates nothing).
- **Structured ledger:** `docs/audits/packages/groups.ledger.json`: 56 findings, 68 refuted leads, 109 checklist answers, 23 handoffs, 15 decisions (14 open), 14 uncertainties (0 open), 143 probe entries, 41 evidence runs. It holds every finding field, the charter, roster and checklist answers, probe metadata and the scorecard.
- **Provenance:** the verified v1 record (2,420 lines), condensed to the v2 format, then completed to skill v2.1: three tie-breaks, four tier-B verifications and tier-A verification of three security findings, all at the same base.

## Summary

`waaseyaa/groups` owns the `group` and `group_type` entities, the membership edge vocabulary, `GroupMembershipService` (GMS) and a capability-scoped staff directory. `waaseyaa/framework`, `cli` and `workflows` install and activate it by default, as #2091 decided; `waaseyaa/core` excludes it.

It works for its one heavy consumer, Sheg. After verification no non-security finding is medium or higher. Its weaknesses are unwritten contracts and tests that don't discriminate:

- Membership writes aren't atomic. Concurrent calls commit duplicate live rows, and the revoke loop can stop halfway (GRP-PERSIST-001 to 003, all under #2762). No consumer outcome changes today.
- Three framework readers disagree on what a live membership is (GRP-DOMAIN-001, GRP-PERSIST-005). Only a manual generic edit produces a row they disagree on.
- The staff policy reads other packages' raw tables and goes blind on the hybrid relationship layout (GRP-LAYER-002).
- The docs describe the 2026-04 surface, and most staff-directory and membership rules have no discriminating test.

Five security findings surfaced here. Groups owns one (GRP-SEC-001); the others are owned by api, audit, relationship and oidc. All are handled privately. A consumer today should bind a valid declaration, resolve the reader lazily, not add a plain protected-read User policy next to the staff directory (GRP-ACCESS-004), and not rely on `addMember` being race-free.

## Charter

- **Owns:** the `group` multi-bundle content entity (keys gid, uuid, type, name, langcode; Data fields status, created_at, updated_at; Protected `members_can_view_directory`, default false); the `group_type` bundle config entity; the `administer groups` permission; `GroupAccessPolicy` (admin-only CRUD, Forbidden to everyone else); `GroupProtectedFieldReadPolicy` (admin-only release of the opt-in); the edge vocabulary `group_membership` and `group_content`; GMS (system-context reads, idempotent upsert, soft revoke over relationship rows); the staff directory (host declaration, contextual User read policy, reader). It creates no table and dispatches or subscribes to no events.
- **Does not own:** relationship storage, validation, integrity and the delete guard; membership uniqueness (#2762); the peer member directory, `AuthorizedRelationshipTraversal::memberDirectory`, which sits in relationship and reads groups' storage (D6 decides its home, GRP-LAYER-001); the `user` entity; workflow group constraints; the `groups:*` CLI; JSON:API routing; bundle-subtable mechanics; membership roles (#1627). Callers own membership-mutation authorization today. The docs claim a tenancy capability the code lacks (GRP-DOCS-001).
- **Consumers:**
  - Runtime: every `AbstractKernel`, HTTP and console, boots `GroupsServiceProvider` and discovers both policies wherever groups is installed. Installing is activating.
  - In-framework: cli (five handlers and a provider), workflows (`GroupConstraintChecker`, reads only), relationship (by string and storage layout, with no composer edge), foundation (a require-dev test).
  - Profiles: `waaseyaa/framework` requires groups directly; cms, full and any cli or workflows install carry it transitively; `waaseyaa/core` excludes it. Every generated app installs it through the skeleton.
  - Split users: Sheg requires groups directly and uses GMS, `GroupRelationshipTypes` and the staff directory. Anokii (alpha.297) and FETDER (alpha.301) install it and use it nowhere.
- **Dependencies:** required and used: access, database-legacy, entity, entity-storage, field, foundation, relationship. Tests only: phpunit, api, routing, user; `symfony/filesystem` and entity's `TestEntityType` are undeclared (GRP-DIST-003). String-level: the `relationship`, `user` and `group` types; membership writes need a registered `user` type and an existing member row (GRP-COMP-002). Boundary adapter: the staff policy's raw reads of the group and relationship tables (GRP-LAYER-002). Inbound string edge: relationship reads groups' table and `_data` keys (GRP-LAYER-001).
- **Public surface:** declared public: `StaffDirectoryPage`, `StaffDirectoryReadDeclaration`, `StaffDirectoryReaderInterface`. `@api` but undeclared: GMS, `GroupRelationshipTypes`, `GroupProtectedFieldReadPolicy`. `@internal`: `CapabilityScopedStaffDirectoryAccessPolicy`, `StaffDirectoryReader`. Five are untagged (GRP-PUBLIC-001). Wire and string contracts: type ids `group` and `group_type`, bundle key `type`, relationship types `group_membership` and `group_content`, table `group` with `gid` and `_data`, permission `administer groups`, JSON:API exposure of both types. No symbol states a compatibility promise.
- **Installation profiles:** #2091 decided the `waaseyaa/framework` closure and its transitive carriers. Groups-alone (standalone split and primitive-only) is undecided (D1).
- **Evidence it works:** source suites pass (groups 86 tests and 739 assertions; consumer contracts 34, workflows 348, relationship 130), and synthetic probes through real `HttpKernel` and `ConsoleKernel` boots confirm discovery, lifecycle, the HTTP refusal stack and the boot failure on a bad declaration. Distributed forms are under "Qualification by profile".

## Roster

All 18 files: the 13 PHP files under `src/`, plus `composer.json`, `public-surface.php`, `README.md`, `CHANGELOG.md` and `phpunit.xml`. Level is the ledger's evidence level; "reproduced (installed bytes)" marks probes that also ran against Sheg's installed split, and the ledger's notes carry the qualifiers (every reproduction used synthetic data).

| File | Role | Classification | Level | Findings |
| --- | --- | --- | --- | --- |
| `composer.json` | identity, 7 runtime requires, discovery entries | necessary but under-specified | reproduced | GRP-DIST-003, GRP-COMP-002, GRP-TEST-012, GRP-DOCS-005 |
| `public-surface.php` | declares 3 of 13 src symbols | duplicated or drifting contract | reproduced | GRP-PUBLIC-001 |
| `README.md` | Packagist description | necessary but under-specified | reproduced | GRP-DOCS-005, GRP-DOMAIN-004, GRP-PRIVACY-001, GRP-DIST-001 |
| `CHANGELOG.md` | package changelog, stale since 2026-05-01 | unwired or obsolete | reproduced | GRP-DOCS-001, GRP-DOCS-002, GRP-DOCS-006 |
| `phpunit.xml` | isolated-checkout config that no gate runs | missing refusal, lifecycle, compatibility or distribution evidence | reproduced | GRP-DIST-003 |
| `src/Group.php` | `group` entity | necessary but under-specified | reproduced | GRP-DOMAIN-001, GRP-DOMAIN-005, GRP-PERSIST-005, GRP-LAYER-001, GRP-DOCS-001 |
| `src/GroupType.php` | `group_type` bundle row | optional/required misrepresented | reproduced | GRP-DOMAIN-004 |
| `src/GroupAccessPolicy.php` | admin-only CRUD policy for both types | necessary but under-specified | reproduced | GRP-ACCESS-001, GRP-TEST-009, GRP-TEST-012 |
| `src/GroupProtectedFieldReadPolicy.php` | admin-only release of the opt-in | necessary but under-specified | reproduced | GRP-PRIVACY-001, GRP-TEST-003, GRP-PUBLIC-001 |
| `src/GroupRelationshipTypes.php` | edge vocabulary constants | duplicated or drifting contract | reviewed | GRP-LAYER-001, GRP-PUBLIC-001 |
| `src/GroupsServiceProvider.php` | registers both types; binds GMS and the lazy reader | owned and coherent | reproduced | GRP-LIFECYCLE-002, GRP-TEST-006, GRP-DOCS-001 |
| `src/Membership/GroupMembershipService.php` | membership reads and soft-revoking writes | duplicated or drifting contract | reproduced (installed bytes) | GRP-PERSIST-001 to 004, GRP-DOMAIN-001 to 003, GRP-LIFECYCLE-001, GRP-COMP-001, GRP-COMP-002, GRP-PUBLIC-001, GRP-DOCS-007, GRP-TEST-002, GRP-TEST-008 |
| `src/StaffDirectory/CapabilityScopedStaffDirectoryAccessPolicy.php` | `@internal` User policy that carries the read policy | owned and coherent | reproduced | GRP-LIFECYCLE-003 |
| `src/StaffDirectory/CapabilityScopedStaffDirectoryReadPolicy.php` | contextual User view over a raw roster read | duplicated or drifting contract | reproduced (installed bytes) | GRP-LAYER-002, GRP-LAYER-001, GRP-PERSIST-005, GRP-ACCESS-002, GRP-DOMAIN-001, GRP-PERF-001, GRP-TEST-001, GRP-TEST-004, GRP-TEST-005 |
| `src/StaffDirectory/StaffDirectoryPage.php` | `@api` result DTO | owned and coherent | reproduced | |
| `src/StaffDirectory/StaffDirectoryReadDeclaration.php` | `@api` host declaration | owned and coherent | reproduced | GRP-LIFECYCLE-003 |
| `src/StaffDirectory/StaffDirectoryReader.php` | `@internal` list and detail reader | necessary but under-specified | reproduced | GRP-DOMAIN-006, GRP-TEST-001, GRP-TEST-007 |
| `src/StaffDirectory/StaffDirectoryReaderInterface.php` | `@api` roster boundary Sheg consumes | necessary but under-specified | reviewed | GRP-DOMAIN-006, GRP-ACCESS-004, GRP-DOCS-005 |

## Intake

No intake: no committed audit ledger or record routed a finding or lead to groups at the base.

## Package findings

43 original IDs in 31 rows: 29 low, 13 info and one security finding. Test and documentation gaps are grouped into one finding per repair slice. A grouped row keeps its lowest ID, lists the rest after "+", and its severity is its highest member's: GRP-TEST-002 is info, and its row is low through GRP-TEST-008.

*Verified* is the tier that ran: A is v1's two independent verifiers, "A + tie-break" adds the v2.1 tie-break, and B is one v2.1 verifier doing both jobs. No non-security finding is medium or higher, so no row has a detail block; every field is in the ledger. GRP-SEC-001 is a public row only. The v1 lenses split low versus info on nine findings (GRP-ACCESS-002, DOMAIN-003, COMP-001, DIST-001, DOCS-006, DOCS-009, TEST-002, TEST-005, TEST-011), which take info without a tie-break: both bands sit below the tier-A threshold, each already had two lenses, and no disposition, destination or security status depends on the band.

| ID | Title | Severity | Confidence | Level | Verified | Disposition | Destination | Next action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `GRP-PERSIST-001` | Concurrent `addMember`/`assignContent` calls commit duplicate live rows | low | confirmed | reproduced (installed bytes) | A + tie-break | repair | #2762, D5 | attach the retained cold race and acceptance to #2762 |
| `GRP-PERSIST-002` | Upsert and revoke use different finders; `addMember` can reactivate a revoked row beside a live one | low | confirmed | reproduced (installed bytes) | A | repair | #2762, D5 | add the three-fixture acceptance |
| `GRP-PERSIST-003` | The revoke-all loop commits row by row and can stop halfway | low | confirmed | reproduced | A | repair | #2762 (can land first), D5 | wrap the loop in one `saveMany()` |
| `GRP-PERSIST-004` | `upsertLiveRelationship` returns silently when the found row vanishes | info | confirmed | reproduced | A | document | #2762, D5 | pick conflict or no-op |
| `GRP-PERSIST-005` | The staff policy and the member directory apply different liveness rules to the same raw rows | low | confirmed | reproduced | A | repair | S4, D8 | decide D8 |
| `GRP-ACCESS-001` | `GroupAccessPolicy`'s Forbidden vetoes application grants; the veto is unstated | low | confirmed | reproduced | A | document | D2, S7 | decide D2 |
| `GRP-ACCESS-002` | Two spec sentences name the wrong enforcer of inactive-user exclusion | info | confirmed | reproduced | A | document | S9 | fix in S9 |
| `GRP-PRIVACY-001` (+TEST-003) | App-defined Protected group bundle fields are unreadable for every principal; the field policy has no test | low | confirmed | reproduced | A | repair | D3, S7 | decide D3 |
| `GRP-DOMAIN-001` | Three framework readers disagree on what a live membership is | low | confirmed | reproduced | A + tie-break | repair after ratifying, or document exceptions | #1627, D4 | ratify during the #1627 rescope |
| `GRP-DOMAIN-002` | `addMember` accepts non-uid identifiers; reactivation of bounded rows is unratified | low | confirmed | reproduced | A | repair | #2762, D4, D5 | add to #2762's acceptance |
| `GRP-DOMAIN-003` | #1627's scope is stale: roles, a listing and membership authority are undecided | info | confirmed | reviewed | A | document | #1627 | restate #1627 against GMS |
| `GRP-DOMAIN-004` | A group's bundle need not be a `group_type`; the docs say products declare bundles through it | low | confirmed | reproduced | A | document | S9 | include in S9 |
| `GRP-DOMAIN-005` | Group timestamps have no producer and lack the `timestamp` subtype | low | confirmed | reproduced | A | repair | #3078 | add group to #3078 |
| `GRP-DOMAIN-006` | The staff reader collapses refusals, misconfiguration and bad pagination into one empty page | low | confirmed | reproduced | A | document, add a diagnostic | S2, D12 | decide D12 |
| `GRP-LIFECYCLE-001` | Soft-revoked edges block deleting groups, former members and content; groups never says so | low | confirmed | reproduced | A | document | #1627, D10; #2733 | document under #1627 |
| `GRP-LIFECYCLE-003` | An invalid host declaration refuses every non-restricted boot; the refusal is untested and undocumented | info | confirmed | reproduced | B | retain fail-loud; add a test and a doc sentence | D13, S2, S9 | confirm D13 |
| `GRP-COMP-001` | GMS's contract omits its reliance on relationship's listeners | info | confirmed | reproduced | A | document | S9, S3 | cite in S3 and S9 |
| `GRP-COMP-002` | Membership writes need a registered `user` type that groups doesn't require; groups-alone and workflows-alone installs lack it | info | confirmed | reproduced | B | document | D1, S9 | decide D1 |
| `GRP-DIST-001` | The default-install profile is recorded only in #2091 and a CI constant | info | confirmed | reproduced | A | document | S9, D1, S11 | add the README profile line |
| `GRP-DIST-003` | The shipped test suite needs undeclared test dependencies | info | confirmed | reproduced | A | accepted residual | residual: isolation is scoped to access | none |
| `GRP-PUBLIC-001` | Declarations cover 3 of 13 symbols; 8 are unclassified | low | confirmed | reproduced | A | document | D9, S8 | decide D9 |
| `GRP-DOCS-001` (+002) | The package CHANGELOG is stale and carries a tenancy recipe that can't run | low | confirmed | reproduced | A | document or remove | D11, S10 | decide D11 |
| `GRP-DOCS-003` | `genealogy-groups-gate.md`'s verdict is undated and its trigger fired unrecorded | low | confirmed | reproduced | A | document | S9; genealogy audit (C1) | correct in S9 |
| `GRP-DOCS-004` (+005, 006, 007) | The package docs are stale: README, GMS docblocks, the workflow spec paragraph, dead references | low | confirmed | reproduced | A | document | S9 | file S9 |
| `GRP-TEST-001` (+004, 006, 007) | Staff-directory tests don't discriminate the principal gate, the one-roster rule, provider resolution or pagination | low | confirmed | reproduced | A + tie-break | repair | S2, D12 | file S2 |
| `GRP-TEST-002` (+008) | Membership tests run only unwired and leave read-side filters and dedupe unpinned | low | confirmed | reproduced | A | repair | S3 | file S3 |
| `GRP-TEST-009` (+011, 012) | Group access composition is pinned only at unit level | low | confirmed | reproduced | A | repair | S1, D2 | land S1 |
| `GRP-LAYER-001` | Hidden reverse edge: relationship hard-codes groups' storage for the member directory | low | confirmed | reproduced | A | document or move | D6, S6 | decide D6 |
| `GRP-LAYER-002` (+TEST-005) | The staff policy relies on entity-storage's blob layout; the hybrid layout empties the roster | low | confirmed | reproduced (installed bytes) | A | repair | D7, S5 | decide D7 with relationship |
| `GRP-PERF-001` | Each staff-directory evaluation scans every membership row | info | confirmed | reproduced | A | accepted residual | residual: about 1 ms at Sheg's scale; revisit near 10k rows | none |
| `GRP-SEC-001` | Group membership rows can be created without a groups-owned authority | withheld | confirmed | reproduced | A | repair | private report (framework advisory; owner `waaseyaa/groups`) | private triage; file when authorized |

## Cross-package findings

Found here, owned elsewhere: intake for the owning audit, not part of groups' remediation or counts. None blocks this assessment. The groups remainders of GRP-ACCESS-004, GRP-LIFECYCLE-002, GRP-PERSIST-006 and GRP-TEST-013 ride S2, S5 and S9.

| ID | Owned by | Title | Severity | Affected consumers | Blocks this assessment | Destination |
| --- | --- | --- | --- | --- | --- | --- |
| `GRP-PERSIST-006` | waaseyaa/relationship | `memberDirectory` returns an empty directory on the hybrid layout relationship documents; its only layout test uses an unbootable table | low | apps calling `memberDirectory` on hybrid tables (none known) | no | relationship audit; D7; groups' fixture remainder in S5 |
| `GRP-TEST-010` | waaseyaa/relationship | `memberDirectory` is tested only from the groups tree, partly by reading relationship's source | low | relationship maintainers; groups split runnability | no | relationship audit, with D6 |
| `GRP-ACCESS-004` | waaseyaa/access | With the staff directory declared, a plain protected-read User policy makes every access-checked User list and count throw | low | hosts that bind the declaration and add such a policy (none known) | no | access audit; S9 documents the host obligation |
| `GRP-LIFECYCLE-002` | waaseyaa/foundation | `package-discovery.md:92` promises boot-time resolution that access-handler-dependent bindings can't meet, and the error doesn't name the service | low | new hosts; relationship, search, genealogy and oidc bindings | no | #3123; groups remainder in S2 and S9 |
| `GRP-DIST-002` | waaseyaa/framework | No check asserts groups behaviour in installed or split form; packaged-form is pinned to alpha.210 | low | Sheg and other split consumers | no | root-aggregate audit; a CI issue under #3118, cross-linked with #2681 |
| `GRP-DOCS-008` | waaseyaa/framework | The drift detector routes `packages/groups` only to `bundle-scoped-storage.md` | low | future groups-only pull requests | no | root-aggregate audit (spec-drift tooling) |
| `GRP-DOCS-009` | waaseyaa/framework | The retired 2026-05 hardening stubs for groups and three sibling packages are unreconciled kitty-specs history | info | none | no | root-aggregate audit (#3118 program-level decision) |
| `GRP-TEST-013` | waaseyaa/entity | Two groups suites hand-wire `ContentEntityBase`'s entity type manager; the wiring is inert | info | none | no | #2729; groups remainder in S2 |
| `GRP-TEST-014` | waaseyaa/framework | The getquery gate doesn't check justification comments; the bypass catalogue lacks GMS's four calls | info | security reviewers using the catalogue | no | gate: accepted residual (#1528 scope); catalogue: access audit |
| `GRP-SEC-002` | waaseyaa/api | A mutation refusal can disclose stored data before authorization | withheld | withheld | no | private report (framework advisory; owner `waaseyaa/api`) |
| `GRP-SEC-003` | waaseyaa/audit | A principal built for an inactive account may keep permission-based authority | withheld | withheld | no | private report (framework advisory; owner `waaseyaa/audit`) |
| `GRP-SEC-004` | waaseyaa/relationship | Membership authority can outlive its revocation | withheld | withheld | no | private report (framework advisory; owner `waaseyaa/relationship`) |
| `GRP-SEC-005` | waaseyaa/oidc | Tokens issued before an account is disabled can keep being renewed | withheld | withheld | no | private report (framework advisory; owner `waaseyaa/oidc`) |

GRP-SEC-002 to GRP-SEC-005 are confirmed, reproduced and verified at tier A, with reach traced across every production source. GRP-SEC-005 surfaced while verifying GRP-SEC-003 and has an independent cause. Co-owning packages of security findings receive them through the maintainer with the brief, not through this record or the ledger. The briefs are in the maintainer's durable private custody (D14).

**Handed-off leads:** 23, in the ledger's handoffs: entity-storage 5, foundation 4, relationship 3 and api 3 (one each held with the private briefs), Sheg 2, the repository root 2, and access, cli, oidc and field 1 each. None blocks this assessment. Two came from v2.1 verification: foundation's undeclared need for a `user` type (H21) and relationship's undeclared `@api` classes (H22).

## Refuted leads

68 leads were refuted: 65 by the lanes and 3 during v1 verification. The refutation check re-read the 65 and found two incomplete; both became findings. The 3 refuted during verification were refuted by a verifier, which is itself their check. v2.1 verification refuted nothing. The ones worth remembering:

- `GRP-R-009`: "groups needs `waaseyaa/user` at runtime" held only for reads; writes need a `user` type (reopened as GRP-COMP-002, now info).
- `GRP-R-060`: "#1623's eager policy fatal doesn't reach groups" held only for unbound dependencies; an invalid declaration refuses boot (reopened as GRP-LIFECYCLE-003, now info).
- `GRP-R-031`: SQLite writer locking doesn't prevent the duplicate race; the find runs outside the write transaction.
- `GRP-R-067`: a validated save refuses group status '1'; only writers that skip validation store it.
- `GRP-R-068`: making only the cli edge optional doesn't make groups opt-in, because workflows also requires it (input for #2821).
- `GRP-R-036`: no src class extends a dev-only symbol, so the `--no-dev` boot risk is absent.
- `GRP-R-052` to `GRP-R-054`: the premises of #1627 ("no membership primitive"), #1957 ("no primitive, API parked") and #1871 ("suppressed") are stale; GMS (#1955, #1956) and the gated API (#2045) exist.
- `GRP-R-059`: the transitive install of groups (minoo #923) is the default #2091 decided, not a groups defect.
- `GRP-R-066`: the drift detector's partial mapping isn't how the groups specs drifted; the mapping postdates every contract change.

## Qualification by profile

One row per piece of evidence. Source evidence is reproduced, never qualified.

| Profile | Supported | Evidence class | Evidence | Gap and owner |
| --- | --- | --- | --- | --- |
| primitive-only | undecided (D1) | source | GMS and the staff-directory classes composed directly in synthetic probes; groups suite (86 tests, 739 assertions) | D1 (groups maintainer under #3118) |
| standalone split | undecided (D1) | none | not run; source review only (GRP-R-036) | D1 (groups maintainer under #3118); if supported, S11 (framework CI) |
| kernel composition | yes | source | synthetic probes through real `HttpKernel` and `ConsoleKernel` boots: discovery, lifecycle, refusal stack, boot failure | none |
| kernel composition | yes | closure artifact | hosted run 36192296774, job 108260041065 (`ci/split-artifact-acceptance`), exact base: boots the framework closure with groups present | groups behaviour not asserted: GRP-DIST-002 (framework CI) |
| kernel composition | yes | installed consumer | Sheg `ee810a0a0`: vendor bytes identical to source except `composer.json` constraints; key probes re-ran on the installed bytes with synthetic data (U2) | Sheg's own suite not run (Sheg #297) |
| metapackage (cms, full) | yes, transitively (#2091) | none | static closure probe only; `ci/core-only-boot` and packaged-form cover `core`, at alpha.210 | GRP-DIST-002 (framework CI) |
| metapackage (core) | no; excluded by design | none | closure probe: core doesn't carry groups | none |
| framework closure | yes (#2091; root `composer.json:348`) | closure artifact | job 108260041065: 79 sealed members, composition, exported files, `--no-dev` install, `install:init` and boot; 16 seeded controls caught; reflection-loads groups' classes; asserts no groups behaviour | groups behaviour in installed form: GRP-DIST-002 (framework CI) |
| generated application | yes (the skeleton requires `waaseyaa/framework`) | closure artifact | job 108260041065 creates a project from the sealed artifacts, runs `install:init` and boots it, in dev and `--no-dev` consumers; asserts no groups behaviour | groups behaviour in a generated app: GRP-DIST-002 (framework CI) |

Every supported profile has evidence or an owned gap, and the undecided ones are dispositioned to D1, so none keeps the audit open.

## Profile checklists

- **General package checklist:** 52 items: 19 answered, 28 findings, 5 don't apply (no frontend or build output, no production change to refresh views for, no Symfony-replaceable mechanism, no emitted payload, no nested wire contract). It now includes the entrypoints-and-wiring and code-quality sections and the internal-layers row. Skill-workflow items: 5 answered.
- **domain-contracts:** 8 items: 3 answered; 5 findings (GRP-DOMAIN-001, 004, 005, GRP-COMP-001, GRP-LIFECYCLE-001, GRP-TEST-002, GRP-LAYER-001).
- **http-ui-contracts:** 11 items: 3 answered; 2 findings (GRP-TEST-011, GRP-ACCESS-001, GRP-PRIVACY-001); 6 don't apply (groups defines no protocol, SPA type, producer, typed fixture, mutation route or HTML form).
- **persistence-execution:** 11 items: 5 answered; 5 findings (GRP-PERSIST-001 to 006, GRP-LAYER-002); 1 doesn't apply (no jobs or retries).
- **kernel-runtime:** 11 items: 7 answered; 4 findings (GRP-LIFECYCLE-002, GRP-PUBLIC-001).
- **distribution:** 9 items: 4 answered; 2 findings (GRP-DIST-001, GRP-DIST-002); 2 don't apply (no static assets or committed build output); 1 gap: groups-alone `--no-dev` install and boot, destined to D1 and S11.

## Decisions and uncertainties

| ID | Decision or uncertainty | Findings | What would settle it | Who decides |
| --- | --- | --- | --- | --- |
| D1 | Is groups-alone (standalone split, primitive-only) supported, and does groups then require `waaseyaa/user`? | GRP-DIST-001, GRP-COMP-002 | a charter ruling; if supported, S11's hosted groups-alone install boots and writes one membership | groups maintainer under #3118 (#2821 for any cli opt-in) |
| D2 | Is `GroupAccessPolicy`'s Forbidden a hard boundary or an extensible default? | GRP-ACCESS-001, GRP-TEST-009 | a ruling stated in the README and `access-control.md`; then S1's kernel test and S7 | groups maintainer, with access |
| D3 | Release rule for application-defined Protected group bundle fields | GRP-PRIVACY-001, GRP-TEST-003 | a ruling; then S7's field-policy tests | groups maintainer |
| D4 | One live-membership definition: status, window, direction, group state, identifier, reactivation | GRP-DOMAIN-001, GRP-DOMAIN-002 | ratification in the #1627 rescope; then the cross-reader conformance test | maintainer, through #1627 |
| D5 | #2762 identity design: enforcement, reactivation, vanished-row outcome, one meaning of idempotent, duplicate cleanup before any constraint | GRP-PERSIST-001 to 004, GRP-DOMAIN-002 | the #2762 design, with the plan's acceptance | #2762 owner |
| D6 | Move `memberDirectory` into groups, or record it as an adapter in relationship | GRP-LAYER-001, GRP-TEST-010 | a ruling; then S6 with relationship's GRP-TEST-010 | groups and relationship maintainers |
| D7 | Supported relationship table layouts; per-field read as an entity-storage seam or local to each reader | GRP-LAYER-002, GRP-TEST-005, GRP-PERSIST-006 | a layout contract in `relationship-modeling.md`; then S5 and relationship's fix | relationship maintainer, with entity-storage |
| D8 | Which layer owns the canonical scalar form of Data fields | GRP-PERSIST-005 | a ruling; then S4 | entity maintainer, with groups |
| D9 | Declare GMS public as a concrete class, or give it an interface | GRP-PUBLIC-001 | a ruling under the @api policy; then S8 | groups maintainer under #3118 |
| D10 | Keep revoked rows blocking deletion, or offer a groups purge | GRP-LIFECYCLE-001 | a ruling in the #1627 rescope | maintainer, through #1627 |
| D11 | Group tenancy unsupported or supported; retiring per-package CHANGELOG files | GRP-DOCS-001, GRP-DOCS-002 | a charter ruling and a docs-governance ruling; then S10 | groups charter; release and docs governance |
| D12 | Staff reader: invalid pagination concealed or thrown; the operator diagnostic; named error or dormant reader without a declaration | GRP-DOMAIN-006, GRP-TEST-001, GRP-LIFECYCLE-002 | a spec ruling; then S2 pins it | groups maintainer (foundation #3123 for the generic message) |
| D13 | Confirm fail-loud boot for an invalid declaration (it meets the contract); cite the offending value in the message or not | GRP-LIFECYCLE-003 | a ruling; S2's test pins the message | groups maintainer |
| D14 | Durable private custody of the security briefs (GRP-SEC-001 to 005) | GRP-SEC-001 to 005 | Settled 2026-09-26: a verified copy into the location the maintainer designated (a SHA-256 inventory matched every file; access limited to the maintainer and SYSTEM) | the maintainer |
| D15 | Does groups claim MySQL or PostgreSQL support? | none | a charter ruling; until yes, the missing server-database evidence is an accepted residual; if yes, a framework CI job with MySQL and PostgreSQL services owns it | groups maintainer under #3118 |

Uncertainties: 14, all settled; each resolution is in the ledger. U1 became GRP-SEC-005, U2 was settled by the skill's distribution profile, and the v1 lane conflicts (U3 to U14) are settled, some by moving the open question to D1, D6, D7 or D12 or to GRP-DOCS-009 and GRP-DIST-002.

## Remediation plan

Proposed slices in dependency order: discriminating tests first, then contracts and behaviour, then surface and docs, then qualification. Each is filed as an issue before its remediation begins; the three existing issues count as filed slices once they carry this acceptance. Full acceptance text is in the ledger. Mutation IDs in the acceptance (A2, B4, ANON and so on) are defined in the ledger's probes, each with its edit and whether the groups suite catches it. GRP-SEC-001 is repaired through its private report, outside this plan.

| Slice | Findings | Acceptance | Depends on |
| --- | --- | --- | --- |
| S1 access-policy discriminators | GRP-TEST-009, 011, 012 | A2 and A2b die; renaming the constant or manifest key alone fails a test; a root kernel test pins allow or refuse per principal and operation | D2 (kernel test only) |
| S2 staff-directory contract and tests | GRP-DOMAIN-006, GRP-TEST-001, 004, 006, 007, GRP-LIFECYCLE-003; groups remainders of GRP-LIFECYCLE-002 and GRP-TEST-013 | bounds and refusals documented; operator diagnostic; B4 and ANON die through `EntityRepository::getQuery()`; B2, B2A, E3, F1, F2, LB1 and R1 die; a test pins the invalid-declaration refusal; the missing-declaration behaviour is pinned per D12; no groups suite calls `setEntityTypeManager()` | D12; D13 (message only) |
| S3 membership-service tests | GRP-TEST-002, 008, GRP-COMP-001 | C4, C7 and X1-X3 die; wired refusals with a listener-removal control | none (the temporal pin waits on D4) |
| #2762 (existing) | GRP-PERSIST-001 to 004, GRP-DOMAIN-002 | one live row after the cold race (planned for retention); three-fixture upsert test; atomic revoke; identifier normalization; duplicate cleanup before any constraint | D5; the `saveMany` change can land first |
| S4 reader liveness parity | GRP-PERSIST-005 | table-driven parity between the staff reader and `memberDirectory` | D4, D8 |
| S5 storage-layout seam | GRP-LAYER-002, GRP-TEST-005; groups remainder of GRP-PERSIST-006 | membership-set parity on blob-only and hybrid layouts; the columns-only branch retired or tested; the columns-only fixture in `AuthorizedMemberDirectoryTest` replaced by a hybrid one | D7; relationship's GRP-PERSIST-006 fix |
| S6 member-directory ownership | GRP-LAYER-001 | opt-in bound through the field definition; G2 dies | D6; relationship's GRP-TEST-010 |
| S7 access veto and Protected fields | GRP-ACCESS-001, GRP-PRIVACY-001, GRP-TEST-003 | the D2 and D3 outcomes documented and pinned in both policy orders | D2, D3, S1 |
| S8 public-surface declarations | GRP-PUBLIC-001 | a governance test that fails today on the three `@api` symbols | D9 |
| S9 package documentation | GRP-DOCS-003, 004, 005, 006, 007, GRP-ACCESS-002, GRP-COMP-001, GRP-COMP-002, GRP-DIST-001, GRP-DOMAIN-004, GRP-LIFECYCLE-003; groups remainders of GRP-ACCESS-004 and GRP-LIFECYCLE-002 | README covers every `@api` class, bindings, a staff-directory section with host obligations, the `user`-type requirement and profiles; specs and docblocks match the wired path; optionally one sentence on the boot refusal | D1 (one sentence) |
| S10 retire the CHANGELOG and tenancy recipe | GRP-DOCS-001, 002 | no live reference to the file; no tenancy recipe for `group`, or a tested supported path | D11; entity moves its recipe first |
| S11 standalone qualification, if D1 supports it | GRP-DIST-001 | a hosted groups-alone `--no-dev` install boots and writes one membership | D1; framework CI |
| #1627 (existing) | GRP-DOMAIN-001, 003, GRP-LIFECYCLE-001 | cross-reader conformance test with expected values per reader; lifecycle documented, linking `relationship-modeling.md:192-201` | D4, D10 |
| #3078 (existing) | GRP-DOMAIN-005 | `timestamp` subtype and a server-owned producer | #3078 |

## Not reviewed

- Installation profiles: a groups-alone `--no-dev` install and boot (D1); a packaged-form consumer at the current version; groups behaviour inside a generated application (GRP-DIST-002).
- Consumer suites (Sheg, Anokii, FETDER); consumers were read-only.
- MySQL and PostgreSQL behaviour of the membership filters, row order and the staff policy's raw `_data` reads. Owner: D15. The PostgreSQL `json_extract` routing gap is handed to entity-storage (H2).
- Races on `unassignContent` (same code path; reasoned, not probed). The `assignContent` race was reproduced in the GRP-PERSIST-001 tie-break.
- Group exposure through field auto-save, workflow-transition routes, `/api/schema/group`, MCP, admin-surface, SSR and entity-reference labels; GraphQL user listings (GRP-ACCESS-004, reviewed only).
- In-memory and custom-storage compositions of the staff policy (reviewed only); `AuthorizedRelationshipTraversal` beyond its groups coupling; the cli outcome mapping (#3035).
- Local runs of the Architecture suite, whole-repo dead-code, packaged-form and split-artifact acceptance (all green hosted at the base).
- The admin SPA's presentation of groups; the genealogy C1 re-evaluation; minoo, the Wiisnin log, the #3001 private artifact and the FW-ARCH-2026-08 A4 report (unavailable).

## Host limits

Local work ran on native Windows 11 with PHP 8.5.5; hosted Linux ran CI (run 36192296774, 57 jobs, green). PHP `symlink()` fails on Windows, so `ComposerProjectFixture` kernel tests error locally; hosted `ci/unit-tests` owns them and passed (#3096, #3081). The POSIX-only gates (`check-split-artifact-acceptance`, `check-fresh-install-boot`, `bin/test-isolated-package`) ran hosted only, and no hosted job installs groups alone (S11 if D1 supports it). Composer commands were out of scope. No MySQL or PostgreSQL server was available and no hosted job covers one (D15). GRP-PERF-001's timings are host-specific.

## Evidence

Key runs only; the ledger lists them all, with each probe's own result.

| Command or probe | Base | Dependency identity | Runner | Proves | Result |
| --- | --- | --- | --- | --- | --- |
| GitHub Actions CI, all jobs (reused) | `a4e88e88a` | lock `3b83a3cc…ca2c6889` | hosted Linux, run 36192296774 | repository gates and test shards, including the relationship wiring tests | 57 jobs, all green |
| `ci/split-artifact-acceptance` | same | same | hosted Linux, job 108260041065 | closure artifact: create-project, dev and `--no-dev` install, `install:init`, boot | passed; 16 seeded controls caught |
| `phpunit packages/groups/tests` | same | same | native Windows 11, PHP 8.5.5 | source | OK (86 tests, 739 assertions) |
| consumer contract files; `packages/workflows/tests`; `packages/relationship/tests` | same | same | same | in-framework consumers | OK (34/134; 348/1229; 130/321) |
| `diff -rq` and SHA-256 of Sheg `vendor/waaseyaa/groups` | same | Sheg `ee810a0a0`, lock `2ac13558…bdb7`, split `b1a4fad0f` | local, read-only | installed consumer bytes | identical except `composer.json` constraints |
| `cold-race.php`, `cold-race-sheg.php` (v1) | same | lock; Sheg identity | local | unforced race | 14/16 and 12/12 rounds leave 2 live rows |
| `run-mutants.sh` (26 mutants) and verification mutants (v1) | same | lock | local (Git Bash runner) | discriminators | 12 killed, 14 survived; B2A, LB1, R1, A2b, ANON, X1-X3, G2 and T1 survive |
| `GroupsKernelLifecycleProbeTest` (v1) | same | lock | local, real kernels | discovery, lifecycle, refusal stack | OK (5 tests, 47 assertions) |
| layers, surface parity, getquery, composer policy, PHPStan on groups src | same | lock | local | gates | all pass |
| v2.1 tie-break, GRP-PERSIST-001: `consumer-outcome-probe.php`, `remove-race-control.php` | same | lock; Sheg identity | local, one process per request | race and every consumer projection | 8/8 and 6/6 rounds live=2; no projection differs; removes converge |
| v2.1 tie-breaks, GRP-DOMAIN-001 and GRP-TEST-001 | same | lock; Sheg src read-only | local | real writer paths; B4 and ANON reach | readers agree on writer paths; suite OK under both mutants, reach probe fails under each |
| v2.1 tier B: GRP-LIFECYCLE-003, GRP-COMP-002, GRP-PERSIST-006, GRP-ACCESS-004 | same | lock; Sheg identity for GRP-ACCESS-004 | local; real kernels | the four verdicts above | all OK as recorded in the ledger |
| private probes for the security findings | same | lock | local | synthetic | withheld; counted on each security finding |

## Scorecard

- **v1 run:** 121 agents. Investigation: 8 agents, 62 min, about 2.9M subagent tokens. Verification and synthesis: 113 agents, 3 h 20 min, about 16.8M tokens. About 4.3 hours in total, with no budget. The v2 sizing for a small package is 3 or 4 lanes, about 50 agents and about 2 hours.
- **v2 condensation:** 9 agents including 3 skill reviewers, 6 h 20 min, about 2.35M subagent tokens (harness usage report).
- **v2.1 completion:** 19 agents (3 tie-breaks, 4 tier-B verifiers, 6 tier-A verifiers, 1 writer, 3 critics, 2 repairs), 2 h 04 min, about 4.8M subagent tokens (harness usage report). No budget was recorded beforehand, and the critic loop ran three rounds against a limit of two without passing; the orchestrator made the remaining fixes directly.
- **Final security verification:** 3 agents (the GRP-SEC-002 reach lens and GRP-SEC-005's two lenses), 18 min, about 0.74M subagent tokens.
- **Scope:** 18 production files (13 PHP under `src/`, 1,063 source lines); consumers cli, workflows, relationship and foundation in the framework, Sheg (uses groups), Anokii and FETDER (install only); no intake; 5 profiles applied, 2 not applied.
- **Findings:** 56 IDs (55 original, plus GRP-SEC-005 from v2.1 verification). Package-owned: 43 IDs (29 low, 13 info, 1 withheld security), shown as 31 rows (21 low, 9 info, 1 withheld; a grouped row takes its highest member's severity). Cross-package: 13 (6 low, 3 info, 4 withheld security).
- **v1 verification reversals:** 0 refuted; 13 severity reductions (3 medium to low, 10 low to info); 2 reframed as security; mechanism corrections in 2 findings; 2 of 65 refutations reopened; 6 findings added after verification; 3 refuted leads added; 1 owner correction.
- **v2.1 verification:** 3 tie-breaks, all low (the v1 ledger's medium on GRP-PERSIST-001 and GRP-DOMAIN-001 is corrected); 4 tier-B verifications; tier A for 3 security findings, GRP-SEC-001's evidence lens with reduced independence (its verifier re-ran copies of the lead's probes). 0 refuted; 2 severity changes (GRP-LIFECYCLE-003 and GRP-COMP-002, low to info); 1 confidence change (GRP-SEC-003, suspected to confirmed); 1 owner change (GRP-SEC-001 to groups) and 1 co-owner added (groups on GRP-PERSIST-006); 1 evidence-level correction (GRP-ACCESS-004, qualified to reproduced); mechanism corrections in 9 findings; 2 handoffs and 1 security lead added.
- **Record length:** v1 2,420 lines; v2 392 lines; this record 264 lines and 6,499 words. Ledger: 486,246 bytes (v2: 478,007).
- **Probes:** 143 ledger entries, including the mutation catalog (36 mutations, each with its edit and whether the groups suite catches it) and 20 security probe files held with the private briefs. Retained, runnable from the repository: `tests/Fixtures/Audits/Groups/GRP-PERSIST-001-duplicate-membership.php` (the interleaved race; exit 0 while #2762 stands) and `tests/Fixtures/Audits/Groups/GRP-TEST-001-capability-gate.php` (the capability-gate discriminator, with `--mutant=B4|ANON` and `--emit-bootstrap` to run the groups suite against a mutant, which passes). No non-security finding is medium or higher, so these are the probes the acceptance cites. The five files planned earlier (a multi-process race and a POSIX mutation runner) are not retained: retained probes are scanned like test files, so the unforced race and the full mutation run stay in the ledger with their reproduction.
- **Open:** 14 open decisions (none blocks the assessment); 0 open uncertainties. Package rows without a filed issue: 22 of 31 (including 2 accepted residuals and GRP-SEC-001, whose private report isn't filed). Cross-package findings without a filed issue: 11 of 13.
