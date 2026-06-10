# waaseyaa/workspace

Opt-in workspace shell primitives (Layer 6 — Interfaces). Not part of the
`full`/`cms` metapackages and not required by the monorepo root: apps that
want the chat-first workspace shell `composer require waaseyaa/workspace`
explicitly. The framework's canonical admin surface remains the Admin SPA
(DIR-007); this package is the deliberate, opt-in alternative shape for
chat-first workspaces, per the Anokii shell-redesign decisions (2026-06-10).

## This cut: the shared chat surface

- `assets/workspace-chat.js` — `WaaseyaaChat.mount(root, config)`: the SSE
  chat client (pending/failed send states, stream-drop resync, paginated
  rehydration, sessionStorage snapshot, proposal cards with viewer mode,
  starter chips, autosizing `<form>` composer with limit counter). Hooks:
  `proposalRouter`, `refreshStrategy`, `md`, per-account `thread.key`.
- `assets/workspace-md.js` — `WaaseyaaMd` markdown renderer (escape-first),
  parameterized by `statusVocab` (table-cell status pills) and `linkPolicy`
  (`blank` / `top` / `panel` / `drop` — no blanket `target="_blank"`).
- `assets/workspace-chat.css` — donor-compatible class names, themed entirely
  via `--ws-*` custom properties.
- `templates/workspace/chat-panel.html.twig` — the panel scaffold (real form
  semantics; deliberately at a two-segment path so the SSR path-template
  fallback can never serve it as a page).
- Assets are served at `GET /workspace/assets/{file}` by
  `WorkspaceServiceProvider` (strict allowlist). An app can shadow any asset
  with a real file at `public/workspace/assets/<file>`.

The transport contract the client speaks is `docs/specs/workspace-chat-surface.md`
in the monorepo. Conversations are account-scoped by contract.

## Later cuts

The canvas+rails layout primitive (hideable sidebars, persisted state, mobile
overlays) and the right-rail preview panel land as subsequent increments of
this package.
