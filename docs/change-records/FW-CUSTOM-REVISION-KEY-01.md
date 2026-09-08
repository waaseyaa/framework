# FW-CUSTOM-REVISION-KEY-01 — configured revision pointers (#3034)

Status: candidate

Forge mirror: #3034

## Problem

`EntityType` and SQL schema generation accept a custom revision key such as
`vid`, but several `EntityRepository` base-row reads and writes still used the
literal `revision_id`. SQL-column entities therefore failed saves or pointer
moves when their generated base table correctly contained `vid` and no
`revision_id` column.

## Contract

- Entity base rows and hydrated entity values use the entity type's configured
  `keys.revision` value, with `revision_id` as the existing default.
- Repository-owned revision-history tables keep their internal `revision_id`
  column and `(entity_id, revision_id)` identity.
- Save, historical load, working-copy selection, rollback, current-pointer
  moves, complete publication, pruning, translation hydration, and initial
  backfill preserve that boundary.
- Aggregate mutation tokens and stale-write refusal are unchanged.

## Evidence

`EntityRepositoryConfiguredRevisionKeyTest` runs the same SQL-column behavior
against `revision_id` and `vid`. It exercises save/load, current-pointer moves,
rollback, complete publication, pruning, backfill, the unchanged internal
history-table column, and stale-token refusal.

## Exclusions

This change does not rename revision-history columns, alter revision allocation,
or revive the removed M-004 parallel revision-storage stack.
