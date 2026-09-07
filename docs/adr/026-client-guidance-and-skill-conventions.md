# ADR-026 — client guidance and skill delivery conventions

- Status: Accepted for this implementation by the Codex root integrator on
  2026-09-06 under the user's delegated Waaseyaa delivery scope. This is an
  agent-owned technical decision, not a claim of independent human approval.
- Stable change record: [FW-CLIENT-SKILLS-01](../change-records/FW-CLIENT-SKILLS-01.md).
- Issue mirror: #2660. Shared root guidance ownership remains #2686.

## Decision

1. Codex and Claude receive the same canonical inventory as separate skills,
   using each client's supported discovery path. Codex uses
   `.agents/skills/waaseyaa-<id>/SKILL.md`; concise root `AGENTS.md` remains
   its always-loaded guidance. The candidate records the primary discovery
   documentation in the transformer and proves installed-package output parity.
2. Unsupported requested capabilities produce deterministic warnings. Existing
   representable output remains available and the command exits successfully
   unless another real error occurs. This does not claim unsupported loading
   mechanics are equivalent. A strict capability-refusal mode is future scope.
3. Concise guidance plus on-demand bodies applies to per-skill clients. Clients
   with consolidated-file delivery retain complete content and receive an
   explicit diagnostic; no skills are silently dropped to meet a size target.

These choices preserve supported content while reducing always-loaded context
for clients with actual per-skill discovery. Hard failure by default would
unnecessarily remove existing consolidated clients; silent flattening would
conceal the capability gap. A second unverified output path or a cosmetic
index would not solve the original discovery/context problem.

## Evidence and boundaries

The candidate's copied-package consumer proof runs without monorepo skill
fallback, compares Claude/Codex IDs, source hashes and bodies, and bounds root
Codex guidance at 16 KiB. That proves generated artifact parity, not every
client's end-to-end runtime behavior. Full candidate qualification and hosted
checks remain landing requirements.

This decision does not resolve #2686 shared `AGENTS.md` ownership, generate MCP
configuration (#2663), or implement generated-state update/verification (#2664).
Those lanes must preserve the explicit capability distinctions here.

## Historical alternatives and review rationale

The following retained discussion predates acceptance. Its references to
Proposed status, pending root review, and unchosen recommendations describe that
historical stage only; the Decision above is the current authority.

## Context

#2660's foundational refactor (Part A of its implementing PR) introduced
`Waaseyaa\Bimaaji\Install\ClientCapabilityRegistry` — a single, data-driven
description of what each of the seven launch clients accepts, replacing
per-transformer `targetPath()` overrides and a hand-rolled Claude constant.
That registry deliberately mirrors the **currently shipped** convention for
every client (verified against vendor docs 2026-08-29, per
`docs/specs/bimaaji-install.md` "Supported clients") and changes no byte of
output. It is the foundation the rest of #2660 needs, but three questions
the issue names as explicitly *not* Claude's to decide gate everything past
that foundation:

- **(a)** Whether Codex should move from single-consolidated `AGENTS.md` to
  a per-skill `.agents/skills/waaseyaa-<id>/SKILL.md` layout, as #2660's
  issue body sketches, or whether that layout is not yet a real Waaseyaa
  (or Codex) convention.
- **(b)** What diagnostic severity (error / warning / silent) a client
  should produce when it cannot represent a capability another client has.
- **(c)** How to divide labour between "concise, always-loaded root
  guidance" and "detailed, on-demand skill bodies" for clients whose vendor
  convention does not offer an on-demand loading mechanism at all.

Each is covered in turn below: current state, options, a recommendation,
tradeoffs, and what accepting it unlocks downstream.

## (a) — Codex per-skill directory delivery

### Current state

**At ADR draft (2026-09-02):** Codex received one consolidated `AGENTS.md`
folding every skill body in full (~116 KB / 3,089 generated lines in the
Claudriel measurement #2660's issue cites). `ClientCapabilityRegistry::default()`
recorded this as `codex => SingleConsolidatedFile`.

**On the review candidate (2026-09-06):** the citation gap named below is
closed. OpenAI documents repository `.agents/skills` discovery walking from
the current working directory up to the repository root, with each skill
directory's `SKILL.md` carrying `name`/`description` metadata, at
<https://learn.chatgpt.com/docs/build-skills#where-codex-loads-local-skills>
(verified 2026-09-05). `CodexClientTransformer`'s class docblock now cites
this source, and `ClientCapabilityRegistry::default()` records
`codex => PerSkillFile` to match, sharing `AbstractPerSkillClientTransformer`
with Claude. Root `AGENTS.md` remains the always-loaded guidance surface per
<https://agents.md> and
<https://learn.chatgpt.com/docs/agent-configuration/agents-md> (verified
2026-08-29); per-skill bodies load on demand from `.agents/skills/`, not
from the guidance file. The accepted Decision above records this implementation
choice under the user's delegated delivery authority; it does not claim
independent human approval or governed landing.

#2660's issue body sketches a target table with Codex receiving
`.agents/skills/waaseyaa-*/SKILL.md` — the same directory-per-skill shape
Claude Code uses. The issue's own "Depends on" line for #2656 is satisfied
(closed). At draft time, nothing in #2656's verification, nor in
`docs/specs/bimaaji-install.md`, established that Codex — or the broader
`agents.md` ecosystem — actually *discovers* a `.agents/skills/` directory
the way Claude Code discovers `.claude/skills/<name>/SKILL.md`; the Claude
convention is documented at <https://code.claude.com/docs/en/skills> and
cited by `ClaudeClientTransformer`'s class docblock, and no equivalent
citation existed for a Codex-side `.agents/skills/` discovery mechanism.
That gap is what the citation above closes.

Writing files a client's own convention does not discover is exactly the
defect #2656 fixed twice already: Claude's flat
`.claude/skills/waaseyaa-<id>.md` and Codex's `.codex/AGENTS.md` were both
files their respective clients silently never read, and the install command
reported them as "written" regardless. The review candidate ships
`.agents/skills/` only with the same class of first-party citation #2656
required for every other client convention — not on the issue-body sketch
alone.

### Options

1. **Ship it now**, on the strength of #2660's proposed table, betting that
   Codex (or Codex-adjacent tooling) already reads `.agents/skills/` or will
   converge on Claude's shape.
2. **Defer** — keep Codex on `SingleConsolidatedFile` until a citable,
   verified Codex-side discovery mechanism for a per-skill directory exists,
   mirroring the evidentiary bar #2656 already set for every other client
   convention in `docs/specs/bimaaji-install.md`.
3. **Hedge** — keep `AGENTS.md` as the authoritative, always-loaded surface
   (so nothing regresses if the bet is wrong) and *additionally* write
   `.agents/skills/waaseyaa-<id>/SKILL.md` as a forward-compatible bet,
   explicitly documented as **unverified** and excluded from any
   "capability-equivalent" claim until verified.

### Recommendation

**Accepted implementation choice (2026-09-06): Option 1 (ship per-skill
delivery), now that the evidentiary bar below is met.** The historical
recommendation (recorded below) was **Option 2 (defer), with option 3 as an
acceptable fallback if the maintainer wanted to hedge** — because at draft
time no citable, verified Codex-side discovery mechanism existed, and
writing undiscovered files is a defect class this codebase had already paid
to fix twice. That deferral rationale no longer applies once a citation is
in place: `CodexClientTransformer`'s docblock now cites
<https://learn.chatgpt.com/docs/build-skills#where-codex-loads-local-skills>
(verified 2026-09-05), the same evidentiary bar every other client
convention in `docs/specs/bimaaji-install.md` already meets. The root integrator
accepted this choice under the user's delegated delivery authority. The
citation and candidate prove the technical basis; governed landing remains a
separate step.

### Tradeoffs

| | Ship now | Defer | Hedge |
|---|---|---|---|
| Risk of shipping undiscovered files | Low — primary-source discovery is now cited | None | Low (isolated to the new path; `AGENTS.md` stays authoritative) |
| Satisfies #2660's literal acceptance table | Yes, subject to candidate qualification and landing | No | Partially |
| Consistent with #2656's evidentiary bar | Yes | Yes | Yes, when documented as additive |
| Extra generated files/complexity per install | +1 file per skill | None | +1 file per skill |

### What accepting it unlocks

Resolves #2660's own "Codex and Claude receive the same canonical skill
inventory as separate, on-demand project skills" acceptance criterion for
real (not just on paper); lets #2663's client/MCP descriptor model declare
Codex's skills capability as `Verified` rather than leaving it undeclared;
and determines whether `ClientCapabilityRegistry`'s `codex` entry ever
changes `skillDelivery` away from `SingleConsolidatedFile`.

## (b) — Diagnostic severity for an unsupported client capability

### Current state

`--features=guidelines,skills` exists on `bimaaji:install` but is purely
advisory — no transformer inspects it, and no code path distinguishes "this
client cannot represent per-skill delivery" from "per-skill delivery was
never requested." A `SingleConsolidatedFile` client silently folds every
skill body into its one file; nothing is printed, warned, or refused.
#2660's acceptance criteria says only: *"A client lacking a capability
produces a deterministic diagnostic; Bimaaji does not silently omit or
flatten that capability."* Silence is explicitly out.

### Options

1. **Hard error** — the run refuses to write anything for a client that
   cannot represent a requested capability, exiting non-zero.
2. **Warning** — the run proceeds with the best representable output (the
   existing full-body fold), prints a structured line naming the gap
   (`Client <id>: skills folded into <path> — per-skill delivery not
   supported by this client's shipped convention.`), and exits 0.
3. **Silent** — status quo. Already rejected by #2660's own acceptance
   language; listed here only for completeness.

### Recommendation

**Option 2 (warning), with hard error reserved for an explicit, opt-in
"require this capability or fail" flag, not the default path.** A hard
error as the *default* behaviour would make `bimaaji:install --client=cursor`
(and the other four consolidated shipped clients) fail out of the box the moment
"skills" becomes a capability those clients are checked against, because
none of the five `SingleConsolidatedFile` clients can represent per-skill
delivery under their own shipped convention — that is not a bug to fix,
it is the shape their vendor supports. Treating "cannot do per-skill" as a
hard failure for clients whose entire shipped design is single-file would
regress `docs/specs/bimaaji-install.md`'s existing seven-client support
matrix rather than improve it. A warning satisfies "not silent" without
making five of seven supported clients newly unusable by default.

`ClientCapabilityRegistry` (Part A, already shipped) is the natural place
this diagnostic reads from: comparing a requested feature against a
client's `SkillDeliveryMode` is now a single lookup rather than logic
duplicated per transformer.

### Tradeoffs

| | Hard error (default) | Warning | Silent |
|---|---|---|---|
| Breaks existing single-file clients by default | Yes | No | No |
| Satisfies "not silent" acceptance language | Yes | Yes | No |
| Operator can miss the gap | No | Low risk (printed, but not blocking) | Yes (status quo defect) |
| Needs a new opt-in flag for strict callers | N/A (is the default) | Yes, if strict mode wanted later | No |

### What accepting it unlocks

Directly satisfies #2660's "deterministic diagnostic" acceptance criterion;
informs #2664's `ai:verify` (which needs to know whether a client's
generated state is "complete" vs "folded/degraded" to verify it correctly)
and #2663's client descriptor model (whether a declared capability is
`supported`, `unsupported`, or `degraded`).

## (c) — Division of labour between root guidance and skill bodies

### Current state

For the five `SingleConsolidatedFile` clients, there is no split at all:
the one guidance file *is* the full body of every skill, concatenated. For
Claude, the split already exists and ships today —
`.claude/CLAUDE-WAASEYAA.md` is a lightweight bullet index (name plus
one-line description per skill); full bodies live only in the per-skill
`.claude/skills/waaseyaa-<id>/SKILL.md` files, loaded on demand by Claude
Code itself. #2660's acceptance criterion — *"Root guidance contains only
concise, cross-cutting instructions; detailed skill bodies are not
flattened into the always-loaded file"* — is already true for Claude and
structurally impossible to satisfy for a single-file client without either
degrading what that client receives or changing what "always-loaded" means
for it.

### Options

1. **Scope the criterion to `PerSkillFile` clients only.** A
   `SingleConsolidatedFile` client's shipped convention has no on-demand
   loading mechanism to split against; its guidance file necessarily *is*
   the full-body delivery, diagnosed as such per (b), not degraded. The
   acceptance bar of "concise root + on-demand detail" applies where the
   client architecture supports on-demand loading at all.
2. **Add an index/appendix split inside the single file** (a concise H2
   index at the top, full bodies below a second boundary) so a human
   skimming — or some future smarter client — sees the concise view first.
   This does not reduce what today's clients actually load: the whole file
   is still read wholesale, so the "116 KB always loaded" problem #2660's
   issue names as the central complaint is unchanged. Cosmetic, not
   structural.
3. **Curate a subset** of skills into the single file and drop the rest
   for single-file clients, trading completeness for conciseness. Rejected:
   it silently breaks #2660's own "same canonical inventory... as separate
   skills" and "capability-equivalent" acceptance criteria by shrinking
   coverage instead of restructuring delivery.

### Recommendation

**Option 1.** The "concise guidance, on-demand detail" split is a real
capability distinction, not a formatting choice — it depends on the client
having *any* mechanism to load a skill body only when relevant, which today
Claude and Codex both have (`AbstractPerSkillClientTransformer`, per the
implementation posture recorded above). Pretending a
single-file client can have both "concise" and "complete" without such a
mechanism produces either option 2's cosmetic non-fix or option 3's
silent coverage loss. Codifying option 1 makes `ClientCapabilities`'
existing `skillDelivery` field double as the answer to "does concise-vs-detail
apply to this client at all" — `PerSkillFile` ⇒ yes, split as Claude already
does; `SingleConsolidatedFile` ⇒ no such split is possible, and (b)'s
diagnostic should say so plainly rather than have `docs/specs/` or a future
verification test assert a "concise root guidance" property that five of
seven clients cannot structurally hold.

### Tradeoffs

| | Scope to PerSkillFile (1) | Index/appendix split (2) | Curated subset (3) |
|---|---|---|---|
| Solves the "always-loaded context size" problem #2660 opened on | Yes, for capable clients; honestly N/A for the rest | No — same bytes loaded either way | Partially, by dropping content |
| Preserves full skill-set parity across clients | Yes | Yes | No — explicitly breaks it |
| New registry/model fields needed | None (derives from existing `skillDelivery`) | A rendering-only change | A selection policy, plus criteria for what's "curated" |
| Honest about vendor limitations | Yes | Implicitly claims a fix that isn't one | N/A |

### What accepting it unlocks

Lets #2660's acceptance language be satisfied precisely instead of
approximately; tells #2665's packaged-consumer acceptance test what
"equivalent agent capability" is allowed to mean per client shape (full
parity of *content*, not identical *loading mechanics*, for clients whose
vendor convention has none); and confirms no new field is needed on
`ClientCapabilities` — `skillDelivery` already carries this distinction.

## Recommended decision ordering

Decide **(a) before (b) and (c)** for Codex specifically: if Codex moves to
`PerSkillFile` under (a), it inherits the Claude-shaped answers to (b) and
(c) for that one client; if it had stayed `SingleConsolidatedFile`, it would
have inherited the historical six-single-file-client answers instead. (b)
and (c) are otherwise independent of each other and of (a) for the other five
clients.

## Adjacent open decision this ADR does not resolve

#2686 — whether Codex's root `AGENTS.md` write can safely coexist with
other clients (Devin Desktop, JetBrains Junie) that read the same shared
path — is a **separate, still-open** issue this ADR does not decide. It
interacts with (a): if Codex moves off `AGENTS.md` toward a per-skill
directory, whether `AGENTS.md` remains Codex's *guidance* surface (likely
yes — `.agents/skills/` would be additive, not a replacement for concise
guidance) still needs #2686's ownership question answered before any
second client is pointed at that same root file.  This ADR flags the
interaction; it does not resolve it.

## What this ADR does not authorize

**Independent human approval or merge closure.** The accepted status records
the root integrator's technical decision under delegated delivery authority;
it does not claim independent human approval, qualification, or governed
landing.

**What the review candidate implements:**
Codex `PerSkillFile` delivery with the verified `.agents/skills/` citation,
warning-severity unsupported-capability diagnostics
(`ClientCapabilityDiagnostics`), and the concise-guidance/on-demand-detail
split scoped to `PerSkillFile` clients (`AbstractPerSkillClientTransformer`,
now shared by Claude and Codex) — see
`docs/specs/bimaaji-install.md` "Client capability model and skill
inventory" for the shipped shape. Part A of #2660
(`ClientCapabilityRegistry` / `SkillInventory` foundation) remains
behaviour-preserving by construction and does not depend on any answer
recorded here. `tests/PackagedForm/check-bimaaji-skill-resources` now
installs copied package bytes from the exact candidate and proves
Claude/Codex skill-id, source-hash, and per-skill-byte parity with no
monorepo skills fallback, while bounding generated root `AGENTS.md` at
16 KiB. Still not implemented by this candidate: any change to #2686's root
`AGENTS.md` shared-ownership question. #2846/#2787 (blueprint
materialization) remain separately gated on #2845 and are untouched by this
document.
