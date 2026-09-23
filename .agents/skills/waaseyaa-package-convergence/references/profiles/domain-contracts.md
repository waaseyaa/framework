# Domain contract checks

Use for packages that own invariants, lifecycle rules, events or extension seams
(entity types, workflows, relationships, taxonomy and similar).

## Invariants and lifecycle

- List the invariants the package promises and where each is enforced. An invariant enforced only in one caller (a controller, a CLI handler, an admin host) is not owned by the package.
- Map each lifecycle: states, allowed transitions, who may trigger them, and what happens to dependents. Test illegal transitions as well as legal ones.
- Find type-specific branches in generic code (a hard-coded entity type in a shared host, for example). Each one is either a missing declaration seam or a documented exception with an owner.

## Events and extension seams

- Record every event the package dispatches or subscribes to: name, payload, ordering, and whether listeners can veto or mutate. Check the order is deterministic and documented where consumers rely on it.
- For each extension seam (interface, attribute, hook), name its real implementations and consumers. A seam with no production implementation is either `@api` scaffolding with a planned consumer or a removal candidate after the dead-code checks.
- Check that best-effort listeners can't break the primary operation, and that required listeners can't be skipped silently.

## Consumers

- Trace actual consumers across packages and downstream applications. The contract is what they rely on, not what the README says.
- Check that access policies for the package's entity types exist, are discovered, and cover field-level access where the data needs it.
