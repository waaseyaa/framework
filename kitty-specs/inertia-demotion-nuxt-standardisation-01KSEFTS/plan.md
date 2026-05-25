# Implementation Plan: Inertia Demotion + Nuxt Standardisation

**Mission:** `inertia-demotion-nuxt-standardisation-01KSEFTS` — see `spec.md`.
**Pattern reference:** charter-amendment-anokii-track-01KSEFE0 for the documentation-only mission shape. Unlike that mission, this one carries three WPs because the source-of-truth is the package README (not the charter), and the manifest + spec sweep are independent edits that can parallelise after the README banner lands.
**Three WPs:** WP01 is sequential (source-of-truth README banner — landing first means WP02's `suggest` description and WP03's spec stamp both have a canonical reference). WP02 and WP03 are parallel after WP01.

## §1 — Canonical README banner text (WP01, exact-match, C-003)

The following block goes at the top of `packages/inertia/README.md`, immediately after the `# waaseyaa/inertia` H1 and a single blank line, before the existing `**Layer 6 — Interfaces**` subhead. Format is a Markdown blockquote (`>`):

```
> **Alternative protocol — not the primary workspace UI.**
>
> Per charter directive **DIR-007** (see `.kittify/charter/charter.md`), the framework's
> committed workspace UI surface is the standalone Nuxt SPA in `packages/admin/`.
> `waaseyaa/inertia` remains supported as an **optional / experimental** L6 protocol
> adapter for distributions that prefer server-driven UI. It is not bundled by
> `waaseyaa/full`; install it explicitly when your distribution chooses Inertia.
```

Followed by the existing README content unchanged. Then, after the existing class summary paragraph, insert:

```
## Status

- **Stability:** optional / experimental. The public API surface (`Inertia::render()`, `InertiaResponse`, `InertiaMiddleware`, `OptionalProp`, `PropResolver`, `InertiaServiceProvider`) is frozen at its current shape. The framework cadence ships no new feature work for this package; community contributions are accepted under the same review bar as any other package.
- **Bundle membership:** suggested by `waaseyaa/full` (not required). To install in a distribution that wants Inertia: `composer require waaseyaa/inertia`.
- **Decision provenance:** charter directive **DIR-007** (ratified by mission `charter-amendment-anokii-track-01KSEFE0`); manifest demotion + this README banner landed by mission `inertia-demotion-nuxt-standardisation-01KSEFTS`.
```

That is the entirety of the WP01 edit: a banner blockquote + a `## Status` section. The rest of the README is unchanged.

## §2 — Canonical `suggest` description text (WP02, exact-match, C-003)

`packages/full/composer.json` — the entry under the `suggest` key for `waaseyaa/inertia`:

```
"waaseyaa/inertia": "Server-side Inertia.js v3 protocol adapter (L6, optional). Install if your distribution prefers server-driven UI over the standalone Nuxt SPA."
```

The full WP02 manifest change in `packages/full/composer.json`:

1. Remove the line `"waaseyaa/inertia": "self.version",` (or whatever the current require constraint is) from the `require` block.
2. Ensure there is a `suggest` block in the file. If absent, add it after `require-dev` (or before `extra`, following the `sort-packages: true` convention — `suggest` ordering within itself is alphabetical-by-name).
3. Add the canonical entry above to the `suggest` block. If `suggest` already had entries, insert it alphabetically.
4. Save. Run `composer update --lock waaseyaa/inertia waaseyaa/full` at the repo root to refresh `composer.lock`. The diff in `composer.lock` should show `waaseyaa/inertia` either removed from the `packages` list (if no other package required it) OR retained but no longer as a transitive of `waaseyaa/full`.

`packages/inertia/composer.json` — read first. If the `description` field already says "alternative" or "optional" or similar, **no edit needed**. If it just says "Server-side Inertia.js v3 protocol adapter for Waaseyaa" (the current state per inventory), update to: `"description": "Server-side Inertia.js v3 protocol adapter for Waaseyaa — optional/experimental L6 surface; see README for the DIR-007 framing."`. No other field changes. **Crucially: do not touch `require`, `require-dev`, `autoload`, or `extra`.**

## §3 — `docs/specs/admin-spa.md` SPA-bet section (WP03)

Insert after the file's existing introduction (whatever the first `## ` heading is — read first), before the next section:

```
## SPA bet (DIR-007)

The framework's committed workspace UI surface is the standalone Nuxt 3 + Vue 3 + TypeScript SPA in `packages/admin/`. This is a constitutional commitment (charter directive **DIR-007**, ratified by mission `charter-amendment-anokii-track-01KSEFE0`), not a default-able preference. Distribution maintainers building on Waaseyaa SHOULD consume the framework's Nuxt SPA either as-is or by extending it via the documented composables + page slots.

`packages/inertia` is the alternative protocol adapter, retained as **optional / experimental**. Distributions that prefer server-driven UI (e.g., for large permission trees, classification rule editors, or multi-tenant policy UI) may install `waaseyaa/inertia` explicitly. It is not bundled by `waaseyaa/full`. See `packages/inertia/README.md` for the Inertia entrypoint and `packages/admin/README.md` for the Nuxt entrypoint.

Changes to this commitment require a charter amendment (per `## Amendment Process` in `.kittify/charter/charter.md`), not just a spec edit.
```

Then stamp the file at the bottom (or wherever existing stamps live — read first) with:

```
<!-- Spec reviewed YYYY-MM-DD - inertia-demotion-nuxt-standardisation-01KSEFTS - WP03 - SPA bet section added per DIR-007 -->
```

`YYYY-MM-DD` is the implementer's edit date (use `date -u +"%Y-%m-%d"`).

## §4 — Documentation audit (WP03)

Run:

```
rg -n -i 'inertia' docs/specs/ packages/admin/README.md
```

For each match:
- If the reference is neutral or already-correct (e.g., a table row listing Inertia alongside other L6 adapters), no edit.
- If the reference frames Inertia as primary, recommended, or default, edit the sentence to clarify it is optional / DIR-007-demoted. Keep edits minimal — one or two words is usually enough.
- Record the file list (edited or not) in the commit message.

`packages/admin/README.md` — read first. If the README already opens with a clear statement that this is the workspace UI, no edit. If not, add a one-line attribution as the first line after the H1:

```
> Primary workspace UI surface per charter directive **DIR-007**.
```

## §5 — CHANGELOG entry (WP03)

`CHANGELOG.md` under `[Unreleased]` → `### Changed`:

```
- Demoted `waaseyaa/inertia` from `waaseyaa/full` `require` to `suggest` (DIR-007 ratification). The package remains supported; it is no longer in the recommended bundle. Distributions that want Inertia: `composer require waaseyaa/inertia`.
```

## Verification gate (each WP, in the mission worktree)

WP01:
1. `git diff packages/inertia/README.md` — confirm only banner + Status section added; no other lines changed.
2. `git diff packages/inertia/src packages/inertia/tests packages/inertia/composer.json` — empty (NFR-001).

WP02:
1. `composer update --lock waaseyaa/inertia waaseyaa/full` succeeds.
2. `bin/check-composer-policy` — green.
3. `bin/check-package-layers` — green (Inertia is still at L6; layer rules unaffected).
4. `git diff packages/inertia/composer.json` — at most a `description` change; no `require` / `require-dev` change.
5. `git diff packages/full/composer.json` — one removal from `require`, one addition to `suggest`.

WP03:
1. `git diff docs/specs/admin-spa.md` — SPA bet section + stamp added.
2. `git diff CHANGELOG.md` — one Changed entry.
3. The commit message enumerates every `docs/specs/*.md` file the audit examined (edited or not).

Mission close:
1. `vendor/bin/phpunit` — green (no code changed; should be unaffected).
2. `composer cs-check && composer phpstan` — green.
3. All `bin/check-*` gates — green, no new findings.
4. `cd packages/admin && npm test && npm run typecheck && npm run lint` — green (no admin code changed; should be unaffected).

## Reviewer focus

- (a) **C-001 / NFR-001 — no code deletion.** `git diff --stat packages/inertia/src packages/inertia/tests` MUST be empty. Reject the PR if anything in `packages/inertia/src/**` or `packages/inertia/tests/**` is in the diff.
- (b) **C-003 — exact-match wording.** Diff the WP01 banner text against §1 character-for-character; diff the WP02 suggest description against §2.
- (c) **FR-003 — `full` no longer requires Inertia.** Look at the post-merge `packages/full/composer.json` `require` block and confirm `waaseyaa/inertia` is absent. Confirm it IS present in `suggest`.
- (d) **FR-004 — lock regenerated.** `git log --oneline -p composer.lock` for this PR should show the lock change in the same commit / PR as the manifest change. Reject if lock is stale.
- (e) **WP02 must not land before WP01.** Check commit ordering: README banner commit precedes manifest commit in the merge history. If implementer used parallel branches, confirm the merge order respects NFR-003.
- (f) **No spec drift.** The WP03 audit list in the commit message MUST enumerate every `docs/specs/*.md` file examined. If the list is missing or partial, the audit isn't complete.
