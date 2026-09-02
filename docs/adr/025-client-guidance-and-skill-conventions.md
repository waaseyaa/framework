# ADR-025 — client guidance and skill delivery conventions (#2660 open questions)

- **Status:** Proposed. Unlike ADR-022/023, this ADR is **NOT accepted on
  merge**. It is a decision memo: it presents options, tradeoffs, and a
  recommendation for three questions #2660 named as explicitly open and
  maintainer-owned, and decides none of them. #2660 Part B's implementing
  PR carries this file only as a `docs/adr/` submission for maintainer
  review; questions (a)-(c) below remain open until a maintainer records an
  explicit decision (by editing this file's Status to Accepted with the
  chosen options named, or by superseding it).
- **Date:** 2026-09-02
- **Anchor issue:** #2660 (parent program: #2653 · milestone S5 · AI-First
  Local Development)
- **Gates:** #2663 (portable client/MCP descriptors need the same capability
  model), #2664 (generated-state update/verification must understand every
  adapter output), #2665 (packaged-consumer acceptance must prove equivalent
  agent capability) — none of these may assume an answer to (a)-(c) that
  this ADR has not recorded as Accepted.
- **Related:** #2656 (per-client convention audit that preceded this),
  #2686 (root `AGENTS.md` ownership across client transformers — a
  **separate, still-open** decision this ADR does not resolve; see
  "Adjacent open decision" below)

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

Codex receives one consolidated `AGENTS.md` folding every skill body in
full (~116 KB / 3,089 generated lines in the Claudriel measurement #2660's
issue cites). `ClientCapabilityRegistry::default()` records this as
`codex => SingleConsolidatedFile`, matching `CodexClientTransformer`'s
shipped output.

#2660's issue body sketches a target table with Codex receiving
`.agents/skills/waaseyaa-*/SKILL.md` — the same directory-per-skill shape
Claude Code uses. The issue's own "Depends on" line for #2656 is satisfied
(closed), but nothing in #2656's verification, nor in
`docs/specs/bimaaji-install.md`, establishes that Codex — or the broader
`agents.md` ecosystem — actually *discovers* a `.agents/skills/` directory
the way Claude Code discovers `.claude/skills/<name>/SKILL.md`. The Claude
convention is documented at
<https://code.claude.com/docs/en/skills> and cited by
`ClaudeClientTransformer`'s class docblock; no equivalent citation exists
for a Codex-side `.agents/skills/` discovery mechanism. `AGENTS.md` itself,
per <https://agents.md> and OpenAI's own
<https://learn.chatgpt.com/docs/agent-configuration/agents-md>, is
documented as a single markdown file loaded into context wholesale — not an
index that triggers on-demand loading of sibling files.

Writing files a client's own convention does not discover is exactly the
defect #2656 fixed twice already: Claude's flat
`.claude/skills/waaseyaa-<id>.md` and Codex's `.codex/AGENTS.md` were both
files their respective clients silently never read, and the install command
reported them as "written" regardless. Shipping `.agents/skills/` for Codex
on the strength of #2660's issue-body sketch alone, without the same class
of verification #2656 required, would risk repeating that defect a third
time — this time for six or seven skills' worth of files per project instead
of one misplaced guidance file.

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

**Option 2 (defer), with option 3 as an acceptable fallback if the
maintainer wants to hedge** — but only if the hedge is documented as
speculative and `docs/specs/bimaaji-install.md`'s "Supported clients" table
is not represented as confirmed the way the other six rows are. Writing
undiscovered files is a defect class this codebase has already paid to fix
twice; a third instance should not ship on an issue-body sketch alone. If
the maintainer has out-of-band evidence (a product announcement, a Codex
CLI changelog entry, direct testing) that `.agents/skills/` is real, that
evidence should be cited in `CodexClientTransformer`'s docblock exactly as
every other client's convention already is, and this ADR should be amended
to Accepted with that citation before Part A's registry gains a
`PerSkillFile` entry for `codex`.

### Tradeoffs

| | Ship now | Defer | Hedge |
|---|---|---|---|
| Risk of shipping undiscovered files | High (unverified) | None | Low (isolated to the new path; `AGENTS.md` stays authoritative) |
| Satisfies #2660's literal acceptance table | Yes, immediately | No, until verified | Partially — present but unverified |
| Consistent with #2656's evidentiary bar | No | Yes | Borderline (documented as unverified) |
| Extra generated files/complexity per install | None yet | None | +1 file per skill, always |

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
(and five of the other six shipped clients) fail out of the box the moment
"skills" becomes a capability those clients are checked against, because
none of the six `SingleConsolidatedFile` clients can represent per-skill
delivery under their own shipped convention — that is not a bug to fix,
it is the shape their vendor supports. Treating "cannot do per-skill" as a
hard failure for clients whose entire shipped design is single-file would
regress `docs/specs/bimaaji-install.md`'s existing seven-client support
matrix rather than improve it. A warning satisfies "not silent" without
making six of seven supported clients newly unusable by default.

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

For the six `SingleConsolidatedFile` clients, there is no split at all:
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
only Claude (and, pending (a), possibly Codex) has. Pretending a
single-file client can have both "concise" and "complete" without such a
mechanism produces either option 2's cosmetic non-fix or option 3's
silent coverage loss. Codifying option 1 makes `ClientCapabilities`'
existing `skillDelivery` field double as the answer to "does concise-vs-detail
apply to this client at all" — `PerSkillFile` ⇒ yes, split as Claude already
does; `SingleConsolidatedFile` ⇒ no such split is possible, and (b)'s
diagnostic should say so plainly rather than have `docs/specs/` or a future
verification test assert a "concise root guidance" property that six of
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
(c) for that one client; if it stays `SingleConsolidatedFile`, it inherits
the six-single-file-client answers instead. (b) and (c) are otherwise
independent of each other and of (a) for the other six clients.

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

Consistent with the constraint that gated #2660 implementation: **no
`.agents/skills/` layout, no capability diagnostic, and no guidance/body
split is implemented by the PR that introduces this file.** Part A of that
PR (the `ClientCapabilityRegistry` / `SkillInventory` foundation) is
behaviour-preserving by construction and does not depend on any answer
recorded here. #2846/#2787 (blueprint materialization) remain separately
gated on #2845 and are untouched by this document.
