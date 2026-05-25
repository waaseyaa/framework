# Anokii v0.1 — Co-Intelligence Workspaces (draft spec, mission seed)

**Draft status:** Seed for future Anokii-repo mission filing via `spec-kitty specify`. Pre-resolved per `anokii-distribution-scaffold-01KSEFT7/spec.md` §Pre-resolved decisions. Re-file in the Anokii repo as `co-intelligence-workspaces-<ULID>` once Wave-2 begins.

**Cross-references:**
- **Framework directives:** DIR-004 (OCAP-by-architecture — Co-Intelligence is THE flagship surface for per-record AI access; consumes the framework substrate mission `per-record-ai-access-flagship-*`), DIR-005 (two-axis storage — AI conversations revisioned + translatable per request), DIR-007 (Nuxt SPA).
- **Anokii directives:** DIR-A001 (AODA — AI response surface is the most novel a11y challenge per design doc §7.2), DIR-A002 (offline-first — AI features require connectivity; explicit graceful degradation), DIR-A003 (translation pipeline — UI strings localised, response language hint to AI), DIR-A005 (OCAP — every AI tool call gates per record).
- **Gap-matrix rows:** A5 (Per-record / per-file AI access controls — flagship integration); A applied throughout Anokii productivity cluster.
- **Design-doc source:** `/tmp/waaseyaa-design-accessibility.md` §7.2 (Co-Intelligence screen-reader announcements).

## Why

Co-Intelligence Workspaces is the Anokii productivity surface that makes the framework's per-record AI access architecture (gap-matrix A5 — the framework's "defining product claim") visible to end users. The framework substrate work (the M-A5 flagship mission `per-record-ai-access-flagship-*`) wires AccessChecker into every `AgentToolInterface::execute()`, adds per-file AI toggles, and routes MCP serializers through FieldAccessPolicyInterface. Anokii at v0.1 takes that substrate and ships a user-facing surface: scoped AI workspaces (per Drive folder, per Data Room, per ad-hoc record set), per-record AI toggles in the surface UX, focus-managed and progressively-announced AI response surfaces (per AODA constraints), and translation-pipeline-aware i18n on user-facing strings.

## Scope

### In scope

- **`co_intel_workspace` entity.** Fields: `id`, `uuid`, `title` (translatable), `description` (translatable), `scope_type` (enum: `drive_folder`, `data_room`, `record_set`), `scope_id` (the scoped entity's UUID), `classification_label`, `owner_id`, `created_at`, `revision_id`. A workspace is bounded — it cannot see records outside its declared scope.
- **`co_intel_conversation` entity** (child of workspace). Records the multi-turn user ↔ AI conversation; revisioned per turn.
- **Per-record AI toggle.** UI affordance on each record in scope: "Include in AI / Exclude from AI". The toggle persists as a field on the record; framework substrate respects it on every tool call per the M-A5 flagship.
- **AI response surface with focus management.** When the user submits a prompt, focus moves to the AI response surface on first response token. AODA: announce "AI is responding..." in `aria-live="assertive"`; stream response tokens to `aria-live="polite"`; on completion, focus an actionable "follow-up" input region.
- **Progressive announcement.** Long responses (> 500 words) render summary-first (first paragraph + "Show more" affordance); SR users hear summary first, then can expand. Multi-step "thinking" state announced as "Thinking... (step N of M)" in `aria-live="assertive"`.
- **Translation pipeline integration per DIR-A003.** UI strings localised through translation pipeline; AI response language hint passed to the model (English default; Anishinaabemowin opt-in when language pack supports it — final scope decided in a separate Anokii AI-language mission).
- **OCAP audit per DIR-A005** on every conversation create, AI tool call, record-read-by-AI, response-emitted, per-record-AI-toggle-flipped, conversation deletion. Audit rows carry `workspace_id` and `record_uuid_at_call_time` for cross-conversation forensics.
- **Offline behaviour per DIR-A002.** AI features REQUIRE connectivity (server-authoritative for safety: no offline LLM, no offline tool execution). Offline mode hides the Co-Intelligence chrome with explanatory message: "Co-Intelligence is unavailable offline. Your prior conversations are browseable here:" + read-only conversation list. Drafted prompts queue for send-on-reconnect.
- **Admin Co-Intelligence UI in Nuxt SPA.** Workspace list (filterable by scope type, classification); workspace detail (record list with per-record toggle, conversation list, conversation detail with focus-managed response surface, audit-log drawer).

### Out of scope

- **Offline LLM execution.** Not at v0.1 (requires model selection + memory budget design + governance for which models are acceptable per Nation).
- **AI training on Nation data.** Not at v0.1 (requires explicit OCAP audit + classification posture work; deferred to a Nation-led decision via OIATC stewards).
- **Custom AI tool authoring by Nation admins.** v1.0 mission.
- **Multi-modal AI** (image / audio input). v0.5 mission.
- **AI cost budgeting / billing UI.** Framework `ai-observability` ships the substrate (M5A mission `ai-observability-dashboard-*`); Anokii Admin Centre surfaces it (admin-centre.spec.md), not Co-Intelligence directly.
- **AI agent autonomy / scheduled background AI tasks.** v1.0 mission — substantial governance work.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `co_intel_workspace` and `co_intel_conversation` entities registered with framework `EntityTypeManager`; revisioned + translatable per DIR-005. |
| FR-002 | Mandatory | Workspaces are scoped — `scope_type` + `scope_id` bound the records accessible to AI; tool calls outside scope are refused per DIR-004 / DIR-A005. |
| FR-003 | Mandatory | Per-record AI toggle persists as field on record; framework M-A5 substrate respects toggle on every tool call (Anokii contract test verifies this end-to-end). |
| FR-004 | Mandatory | AI response surface: focus moves to response area on first token per DIR-A001; "AI is responding..." announced via `aria-live="assertive"`; response chunks via `aria-live="polite"`; completion focuses follow-up input. |
| FR-005 | Mandatory | Long responses (> 500 words) render summary-first with expandable detail; multi-step "thinking" state announced "Thinking... (step N of M)" in `aria-live="assertive"`. |
| FR-006 | Mandatory | UI strings routed through translation pipeline per DIR-A003; AI response language hint passed to model. |
| FR-007 | Mandatory | OCAP audit per DIR-A005 on conversation create, AI tool call, record-read-by-AI, response-emitted, per-record-AI-toggle-flipped, conversation deletion. Audit rows carry `workspace_id` + `record_uuid_at_call_time`. |
| FR-008 | Mandatory | Offline behaviour per DIR-A002: Co-Intelligence chrome hidden with explanatory message; prior conversations browseable read-only; drafted prompts queue for send-on-reconnect. |
| FR-009 | Mandatory | Admin Co-Intelligence UI in Nuxt SPA: workspace list, workspace detail (record list with toggles + conversation list + conversation detail with focus-managed response surface + audit-log drawer). |
| NFR-001 | Mandatory | AI tool calls MUST never bypass AccessChecker — every record read by an AI tool is access-checked per DIR-004 as if a user requested it. (Contract test verifies; this is the gap-matrix A5 flagship behaviour.) |
| NFR-002 | Mandatory | Per-record AI toggle change MUST take effect on the very next tool call — no caching of stale toggle state. |
| NFR-003 | Mandatory | axe-core CI gate passes for Co-Intelligence response surface — this is the highest-risk a11y surface in v0.1 (novel announcement patterns). |
| C-001 | Constraint | NO offline AI execution (FR-008) — security + governance posture is to keep AI server-side. |
| C-002 | Constraint | NO bypass of M-A5 substrate per-record access controls — Anokii surface composes on the framework substrate, never reimplements or weakens it (DIR-A005). |
| C-003 | Constraint | NO AI training on Nation data without explicit Nation-level governance via OIATC stewards channel (out-of-scope at v0.1; documented for clarity). |
| C-004 | Constraint | Workspace scope is hard-bounded — `scope_type=record_set` may not be expanded retroactively without creating a new workspace + audit trail of why the original was insufficient. |

## Acceptance

- All FRs met.
- Per-record AI toggle smoke: toggle a record OFF; submit prompt referencing the record; AI refuses with explanation; audit row captures the toggle state at call time.
- AODA smoke (Co-Intelligence specific): SR user submits prompt; "AI is responding..." announced; response streams via polite live region; long response summarised first; "Thinking" steps announced; focus lands on follow-up input on completion. axe-core green.
- Offline smoke: enter offline mode; Co-Intelligence chrome hidden with message; prior conversation list browseable read-only; type a prompt; come back online; prompt queues and submits.
- Contract test: AI tool call against a workspace scoped to Drive folder X; tool attempts to read a record in folder Y; AccessChecker refuses; audit row captures the refusal per NFR-001.

## Risks

- **Per-record AI toggle is the substrate-dependency risk.** This surface is only useful if the framework M-A5 mission has landed. Mitigation: explicit pre-condition; Anokii Co-Intelligence mission filing waits on M-A5 completion.
- **AODA novelty of focus + streaming patterns.** Few accessible-AI-response patterns exist in the wild. Mitigation: implementation plan calls out a per-component a11y review with a screen-reader user prior to release; pattern documented in `aoda-aa-baseline.spec.md`.
- **AI cost runaway.** A user can in principle prompt continuously. Mitigation: framework `ai-observability` (M5A) provides per-workspace cost tracking; Admin Centre surfaces budgets; v0.1 ships per-workspace soft cap with admin alerting (hard cap is v0.5).
- **Translation pipeline interaction with AI response.** A response generated in English then displayed in Ojibwe requires either runtime translation OR a multilingual model. v0.1 ships English-only AI responses; localised UI chrome only. Multilingual response is a future mission once language scope clarifies.

## Out-of-band

- Offline LLM execution → v1.0+ Anokii mission (model selection + governance).
- AI training on Nation data → Nation-led decision via OIATC; not a v0.1 engineering mission.
- Custom AI tool authoring → v1.0 Anokii mission.
- Multi-modal AI → v0.5 Anokii mission.
- AI cost budgeting UI → Admin Centre surface (admin-centre.spec.md) reads `ai-observability` data.
- Scheduled background AI tasks → v1.0+ Anokii mission.
- Multilingual AI response → research mission; depends on language scope clarification.
