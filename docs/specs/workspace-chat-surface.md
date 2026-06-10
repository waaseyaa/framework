# Workspace Chat Surface

Status: active (first shipped in the cut that introduces `packages/workspace`)
Owner: packages/workspace
Reference consumer: the Anokii Co-Intelligence controller (fnpi-waaseyaa `CoIntelligenceController`), which this contract was extracted from and which adopts it verbatim in its next increment.

The workspace chat client (`packages/workspace/assets/workspace-chat.js`) is a
transport-agnostic SSE chat surface. Anything that satisfies this contract can
drive it: an app controller today, the framework `ai-agent` run transport
(`AgentRunController` / `AgentRunBroadcaster`, see broadcasting.md) later —
**replacing the server must never require client changes**.

## 1. Endpoints

The client is configured with three endpoint URLs; their paths are the
consumer's choice. All are same-origin and session-authenticated.

### 1.1 `send` — POST, JSON in, SSE out

Request: `{ "question": string, "conversation_id": int }` —
`conversation_id` 0 or unknown means "open a new conversation".

Validation failure (e.g. over the question length limit) MUST be a non-2xx
JSON response of the shape:

```json
{ "ok": false, "error": "human-readable reason", "limit": 4000 }
```

`limit` is REQUIRED on length rejections (machine-readable; the client mirrors
it into the composer's maxlength and counter). The client renders the user's
turn in a *pending* state until the stream acknowledges (first `meta` event);
a rejection marks the turn *failed* with the server's reason and restores the
composer text when the composer is still empty. Servers MUST NOT record a rejected turn.

Success: `text/event-stream` (`X-Accel-Buffering: no`), frames separated by
`\n\n`, each frame `event: <name>` + one `data: <json>` line.

### 1.2 `apply` — POST, JSON in, SSE out

Request: `{ "token": string, "decision": "approve" | "reject" }`.
Same stream framing as `send`. The server MUST enforce that only the
proposal's proposer account can decide it (403 otherwise).

### 1.3 `messages(id)` — GET, paginated JSON

`GET <messages-url>?limit=<n>&before=<message-id>`

```json
{
  "ok": true,
  "messages": [ { ...newest page, oldest first... } ],
  "has_more": true,
  "oldest": 412
}
```

- Without `before`: the LAST `limit` turns (the newest page).
- With `before`: the `limit` turns older than message id `before`.
- `oldest` is the smallest message id in the returned page; the client passes
  it back as the next `before` ("Load earlier messages").

Message shape:

```json
{
  "id": 412,
  "role": "user" | "assistant",
  "author": "display label",
  "content": "text / markdown",
  "sources": [ { "title": "...", "source_url": "..." } ],
  "proposal": {
    "token": "...",
    "summary": "...",
    "diff": [ { "field": "...", "before": ..., "after": ... } ],
    "destructive": false,
    "status": "pending" | "applied" | "rejected",
    "proposer_uid": 1,
    "proposer_label": "Russell"
  }
}
```

Per-message `id` and `author` are currently informational to the reference
client (`id` powers the response-level `oldest`/`before` chain; `author`
matters if shared threads ever return as the explicit feature section 3
reserves) - servers must send them, but approximate `author` labels break
nothing visually.

`sources` and `proposal` are optional. A `pending` proposal rehydrates as an
actionable card for its proposer and as a **viewer-mode card** ("Awaiting
<proposer>") for every other account — the client decides by comparing
`proposer_uid` to its configured `user.id`, and the server's 403 on `apply`
is the backstop, not the UX.

## 2. SSE event vocabulary

| event | data | meaning |
|---|---|---|
| `meta` | `{ "conversation_id": int, "title": string }` | First frame. Acknowledges the turn (the client clears the pending state and remembers the thread). `title` is required on `send` streams and optional on `apply` streams. |
| `delta` | `{ "text": string }` | Streamed answer text, append-only. |
| `done` | `{ "sources": [ { "title", "source_url" } ] }` | Turn finished; optional grounding sources rendered under the bubble. |
| `proposal` | `{ "token", "tool", "summary", "diff": [{field,before,after}], "destructive": bool, "target": { "entity_type", "id", ... }, "proposer_uid": int, "proposer_label": string }` | The agent paused on a write needing approval. The client offers it to the app's `proposalRouter` hook first (e.g. to preview on an entity card via `target`); unrouted proposals render as an inline card. |
| `applied` | `{ "ok": bool, "summary", "error"?, "rejected"? }` | An approve/reject executed. On `ok:true` the client invokes the app's `refreshStrategy` hook (default: a quiet notice). |

Unknown events MUST be ignored by the client (forward compatibility) and MAY
be added by servers without a contract bump.

`proposer_uid` / `proposer_label` on `proposal` are REQUIRED as of this
contract (the live event is always "mine" today, but the same envelope is
persisted and rehydrated, where ownership matters).

## 3. Conversations are account-scoped

Per the shell-redesign decision (2026-06-10): conversations belong to the
account that opened them.

- `messages`, `send` (resuming), and any recents listing MUST filter by the
  signed-in account; another account's conversation id is a 404, not a view.
- Workspace-shared threads, if ever wanted, return as an explicit feature on
  top of this default — never as the default.

### Thread-key convention

The client persists the active thread id per account:
`ws.chat.<surface>.<uid>` (e.g. `ws.chat.anokii.1`) in localStorage. The
consumer interpolates the uid server-side when configuring the client. A
server-provided deep link (`?c=<id>` rendered into `thread.initial`) takes
precedence over the stored key for that page view and then becomes the stored
key. sessionStorage `<key>.snap` holds a render snapshot to avoid repainting
the canvas on every full-page navigation; it is display-only and re-synced
from `messages` in the background.

## 4. Client behavior guarantees

- A user turn is never displayed as sent before the server acknowledges it
  (pending → sent on `meta`; pending → failed with the server's reason on
  rejection; the composer text is restored when the composer is still empty,
  so nothing typed since is clobbered).
- A dropped stream mid-turn resynchronizes from `messages` instead of
  dead-ending (the conversation store is the source of truth).
- The composer is a real `<form>`: Enter submits, Shift+Enter inserts a
  newline, the send control is a `type="submit"` button.
- The question-length limit is enforced (maxlength) and surfaced (counter at
  80%+) from the configured `limit`, which consumers should source from the
  same constant the server validates with.

## 5. What this contract deliberately does not cover

Tool/agent semantics (what produces proposals), retrieval/grounding, and the
approval side-effects (attribution, revision logs) are server concerns. The
preview panel, layout shell, and on-canvas proposal targeting conventions are
later workspace cuts; the `proposalRouter` and `data-ws-open="panel"` seams
exist so those land without touching this contract.
