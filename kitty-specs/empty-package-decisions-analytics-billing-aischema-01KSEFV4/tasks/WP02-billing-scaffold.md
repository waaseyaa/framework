# Work Package Prompt: WP02 — billing scaffold marking

**Mission:** `empty-package-decisions-analytics-billing-aischema-01KSEFV4`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** none. Executes in parallel with WP01 and WP03.

## CRITICAL — work in the lane worktree

`documentation` execution mode. Edits are limited to `@api` PHPDoc additions in `packages/billing/src/` and a README rewrite. No method-body edits, no behaviour changes.

## What you are doing

Verify every public class in `packages/billing/src/` has a class-level `@api` PHPDoc tag (the dead-code gate's signal). Author `packages/billing/README.md` documenting the package as v0.1 scaffolding with a clear "Out of scope for v0.1" statement.

## THE pattern to mirror (read first)

- `packages/groups/README.md` or `packages/analytics/README.md` — for the package-README shape Waaseyaa uses.
- CLAUDE.md §"Marking intentional scaffolding" — for the `@api` PHPDoc convention.
- `BillingManager.php` — already has `@api`; that is the marker shape to replicate on the other classes.

## Subtasks

### T013 — `@api` coverage scan

For each file in `packages/billing/src/`:

```bash
for f in packages/billing/src/*.php; do
  if ! grep -q "@api" "$f"; then
    echo "MISSING @api: $f"
  fi
done
```

For each MISSING entry, open the file and add `/** @api */` immediately above the `class` / `interface` / `final class` keyword. Apply to:
- `BillingManager.php` — verify (likely already present).
- `StripeClientInterface.php`
- `FakeStripeClient.php`
- `PlanTier.php`
- `WebhookHandler.php`
- `CheckoutSession.php`
- `SubscriptionData.php`

If the file already has a PHPDoc block on the class, add `@api` as a tag inside the existing block; do not create a duplicate block.

### T014 — Author README

Replace `packages/billing/README.md` with the content shape in `plan.md` §"WP02 — Author README". Required sections (in order): Purpose, Status, Public API surface (table of every `@api`-marked class), Stripe integration shape, Out of scope for v0.1, Tests.

Length verification: `wc -l packages/billing/README.md` must return a number between 80 and 200 (FR-011). If under 80, expand the "Public API surface" and "Out of scope for v0.1" sections with more detail. Do not pad with fluff.

### T015 — CLAUDE.md orchestration row verification

Locate the row in CLAUDE.md orchestration table:

```
| `packages/billing/*` | — | `packages/billing/README.md` |
```

Per the inventory, the cold-memory cell already points at `packages/billing/README.md`; no edit expected. If the cell currently shows `—`, replace with `packages/billing/README.md` to satisfy FR-012.

### T016 — Verification

```bash
grep -L "@api" packages/billing/src/*.php   # no matches
wc -l packages/billing/README.md            # between 80 and 200
grep -c "Out of scope for v0.1" packages/billing/README.md   # >= 1
composer check-composer-policy
bin/check-package-layers
./vendor/bin/phpunit packages/billing/tests/
```

All must succeed.

### T017 — Commit + PR

Commit:
```
docs(billing): mark as v0.1 scaffold; @api coverage on public surface

Adds class-level @api PHPDoc to every public class in packages/billing/src/ so
the dead-code gate treats them as load-bearing-by-design. Replaces README with
a scope statement clarifying that billing is post-v0.1 scaffolding.

Refs: empty-package-decisions-analytics-billing-aischema-01KSEFV4 (WP02)
```

PR title:
```
docs(billing): mark as v0.1 scaffold; @api coverage + scope README
```

PR body cites: this mission slug, the inventory's "sketch" grade, the alpha-to-beta plan's deferral of billing work, sibling WPs (WP01, WP03).

## Verification gate (in lane worktree)

- T016 all green.
- `git status` shows only `packages/billing/src/*.php` (PHPDoc edits) and `packages/billing/README.md` modified (and CLAUDE.md if T015 required an edit).

## Commit + handoff

Open PR; cross-reference WP01 and WP03 PRs.

## Report back with

- Count of files that needed `@api` added.
- Final README line count.
- PR URL.

## Activity Log

_(populated during execution)_
