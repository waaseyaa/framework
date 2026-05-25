# waaseyaa/inertia

> **Alternative protocol — not the primary workspace UI.**
>
> Per charter directive **DIR-007** (see `.kittify/charter/charter.md`), the framework's
> committed workspace UI surface is the standalone Nuxt SPA in `packages/admin/`.
> `waaseyaa/inertia` remains supported as an **optional / experimental** L6 protocol
> adapter for distributions that prefer server-driven UI. It is not bundled by
> `waaseyaa/full`; install it explicitly when your distribution chooses Inertia.

**Layer 6 — Interfaces**

Server-side Inertia.js v3 protocol adapter for Waaseyaa.

`Inertia::render($component, $props)` produces an `InertiaResponse` that distinguishes initial full-page loads (HTML root template) from XHR navigation (JSON payload). `InertiaMiddleware` reads the `X-Inertia-*` request headers and switches the response shape accordingly. `OptionalProp` and `PropResolver` defer expensive prop computation when partial reloads only request specific keys.

Key classes: `Inertia`, `InertiaResponse`, `InertiaMiddleware`, `InertiaServiceProvider`, `OptionalProp`, `PropResolver`.

## Status

- **Stability:** optional / experimental. The public API surface (`Inertia::render()`, `InertiaResponse`, `InertiaMiddleware`, `OptionalProp`, `PropResolver`, `InertiaServiceProvider`) is frozen at its current shape. The framework cadence ships no new feature work for this package; community contributions are accepted under the same review bar as any other package.
- **Bundle membership:** suggested by `waaseyaa/full` (not required). To install in a distribution that wants Inertia: `composer require waaseyaa/inertia`.
- **Decision provenance:** charter directive **DIR-007** (ratified by mission `charter-amendment-anokii-track-01KSEFE0`); manifest demotion + this README banner landed by mission `inertia-demotion-nuxt-standardisation-01KSEFTS`.
