# Welcome Page Design

**Date:** 2026-03-09
**Status:** Approved

## Goal

Ship a branded welcome homepage for freshly generated Waaseyaa apps — a self-contained page that introduces the framework and guides developers on next steps. Similar in spirit to Laravel's welcome page but combining brand identity with getting-started content.

## Visual Identity

- **Name meaning:** "Waaseyaa" — Ojibwe for light/shining
- **Color palette:** Warm golden amber (#F59E0B range) accent, dark charcoal (#111827) background, cream/white text. Radial glow motif behind wordmark.
- **Typography:** Inter via Google Fonts
- **Tone:** Confident, minimal, warm

## Template Resolution

Add `home.html.twig` to the template lookup for the `/` path in `RenderController::renderPath()`. Order becomes:

1. `home.html.twig` (only when path is `/`)
2. `page.html.twig`
3. `ssr/page.html.twig`
4. Hardcoded fallback

## Page Layout

Single-scroll, no navigation. Four sections:

1. **Hero** — Spaced wordmark, tagline ("A light for the modern web"), feature pills (PHP 8.3+, 7-layer architecture, JSON:API, Entity system, AI-ready pipeline). Radial amber glow behind wordmark.
2. **Quick links** — Three cards: Admin SPA (`/admin`), API (`/api`), Documentation. Subtle hover effects.
3. **Getting started** — CLI commands in styled code blocks (`bin/waaseyaa serve`, `entity:create`, `config:export`). Configuration pointers (database, config dir, templates dir).
4. **Footer** — How to replace this page (edit/delete `templates/home.html.twig`), GitHub link, version.

## Constraints

- Self-contained: all CSS inline, no JS required
- Single external request: Google Fonts (Inter)
- No new PHP classes, controllers, routes, or dependencies
- Developer removes the page by deleting `templates/home.html.twig`

## Files Changed

| File | Change |
|---|---|
| `packages/ssr/src/RenderController.php` | Prepend `home.html.twig` to candidates when path is `/` |
| `skeleton/templates/home.html.twig` | New — the welcome page |
| `packages/ssr/templates/home.html.twig` | New — framework-level fallback copy |
