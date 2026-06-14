# ADR NNN — Minoo / `waaseyaa/groups` reconciliation for shadow-class removal

**Status:** Accepted
**Date:** 2026-04-19
**Extends:** ADR 0001 — Group polymorphism via `waaseyaa/groups` + per-bundle subtables
**Related:** framework#1315, framework#1313, minoo#741
**Reference spec:** `docs/history/superpowers/specs/2026-04-19-groups-extraction-arc-close-design.md` (Phase 0 produces this ADR; Phase 5 executes it)

## Context

When Minoo adopted `waaseyaa/groups` in PR #740, canonical `Waaseyaa\Groups\Group` replaced Minoo's `App\Entity\Group` as the registered entity class. Two incompatibilities kept the Minoo-side shadow classes in place:

1. Minoo's shadow `App\Entity\Group` implements `App\Entity\HasCommunityInterface` via `HasCommunityTrait`. Canonical `Waaseyaa\Groups\Group` does not, and cannot — the framework package must not import a Minoo-specific domain concept.
2. Minoo's shadow `App\Entity\GroupType` uses entity keys `['id' => 'type', 'label' => 'name']`. Canonical `Waaseyaa\Groups\GroupType` uses `['id' => 'id', 'label' => 'label']`.

Until both are resolved, `App\Entity\Group.php` and `App\Entity\GroupType.php` must stay (still imported by 11 Minoo test files) and minoo#741 cannot land. This ADR records the two decisions that unblock it. Both are Minoo-side only.

## Decision 1 — `HasCommunityInterface` placement: domain adapter in Minoo

Minoo introduces `App\Domain\GroupCommunity`:

```php
final class GroupCommunity
{
    public function hasCommunity(Group $group): bool;
    public function communityFor(Group $group): ?Community;
}
```

Every `instanceof HasCommunityInterface` callsite migrates to this service (`hasCommunity()` for predicates, `communityFor()` for retrieval). `App\Entity\HasCommunityInterface` and `App\Entity\HasCommunityTrait` are deleted. The `community` entity reference remains a bundle-field on the Minoo group bundles that need it, registered via `EntityTypeManager::addBundleFields()`. The field-name → entity-resolution mapping lives **only** inside the service.

### Alternatives rejected

- **Field-presence checks on every callsite** (`$group->hasField('community') && $group->get('community')?->getEntity()`). Same scope as the adapter — same callsites touched, same interface and trait deleted — but trades capability-via-class for stringly-typed field access. The literal `'community'` becomes a magic constant every caller must know; every caller repeats the presence-and-retrieval dance; future reshape of the field (rename, recomposition, multi-valued variant) ripples through every callsite. The same failure mode the shadow classes embody, re-encoded as string literals instead of class hierarchy.
- **Boundary decorator in Minoo** (`App\Entity\CommunityAwareGroup` wrapping canonical `Group` at repository boundaries, implementing `HasCommunityInterface`). Keeps existing `instanceof` checks working. Cost: two types referring to the same storage row; runtime decoration must be remembered at every load path; re-introduces a shadow variant at runtime. A step sideways from the problem this arc is closing.
- **Lift `HasCommunityInterface` into framework.** Violates the architectural rule that `waaseyaa/*` packages do not own or import Minoo-specific entity types and domain concepts (see CLAUDE.md, *Architectural Boundaries*). Not considered further.

## Decision 2 — `GroupType` entity-key naming: migrate Minoo columns to framework shape

Minoo ships a migration renaming `group_type.type` → `group_type.id` and `group_type.name` → `group_type.label`. Seed fixtures update accordingly. After the migration, canonical `Waaseyaa\Groups\GroupType` with keys `['id' => 'id', 'label' => 'label']` is the single source of truth for this entity's schema.

### Alternatives rejected

- **Teach framework `EntityType` to accept overridable key names per consumer.** Speculative generality: no second consumer needs alternate keys today. Miikana has not adopted `waaseyaa/groups` yet; no other consumer is on the horizon. Would pollute the framework surface with a configuration hook that exists only to indulge one caller's column-naming preference. Rejected.

## Consequences

### Positive

- `App\Entity\Group.php` and `App\Entity\GroupType.php` become deletable. Both blockers for minoo#741 are resolved by work that lands entirely in Minoo.
- Eliminates capability-via-class as a pattern in Minoo's Group callsites, without re-encoding it as stringly-typed field access.
- Field-name → entity-resolution for community membership lives in one file (`GroupCommunity`). Rename or reshape of the underlying field requires changes in one place.
- Framework surface stays opinionated: one canonical shape for `group_type`, one canonical class for `Group`. No per-consumer key overrides.

### Negative / costs

- One-time data migration on every Minoo environment (dev, staging, prod). Requires a rollback path in case of regression.
- Every `instanceof HasCommunityInterface` callsite changes — roughly the set of files flagged in minoo#741's test list plus production callsites. Code churn is concentrated in the arc-close spec's Phase 5 PR.
- Tests that exist only to verify interface/trait mechanics (e.g., `GroupHasCommunityTest`) either rewrite to assert service behavior or disappear.

### Neutral

- The underlying `community` bundle-field continues to exist and carries the storage. `GroupCommunity` is the access boundary, not a replacement for storage.

## Enforcement

The arc-close spec's Phase 5 exit criteria operationalize this ADR:

1. `grep -r 'HasCommunityInterface\|HasCommunityTrait' src/ tests/` returns zero matches post-migration.
2. `grep -rn "get('community')" src/ tests/` returns matches bounded to `App\Domain\GroupCommunity` only — no scattered string-literal field access.
3. Data migration applies cleanly on fresh DB and prod-shaped DB; rollback verified.

## References

- **ADR 0001** — Group polymorphism via `waaseyaa/groups` + per-bundle subtables (canonical decision this ADR extends).
- **Arc-close spec** — `docs/history/superpowers/specs/2026-04-19-groups-extraction-arc-close-design.md`.
- **framework#1315** — kernel-path integration test (harness the Phase 5 migration relies on through #1313's validation).
- **framework#1313** — shadow-collision guard (makes shadow re-introduction a hard error post-cleanup).
- **minoo#741** — shadow-class cleanup (the issue this ADR unblocks).
