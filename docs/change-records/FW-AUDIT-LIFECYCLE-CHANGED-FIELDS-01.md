# FW-AUDIT-LIFECYCLE-CHANGED-FIELDS-01

Local upstream correction for canonical entity-write audit metadata. Publication and deployment are separate authorities.

The lifecycle listener still called removed getDirty(), so current sealed framework entities always emitted dirty_fields=[] even when status changed. Use the public lifecycle EntityEvent.originalEntity and the existing framework-internal EntityValueComparator over the union of field names. The comparator returns sorted changed names only. No protected value, whole value bag, new application journal, directional publication event or persistence write is introduced. Missing originals and third-party entities retain empty changed-name metadata. The original is the repository lifecycle base snapshot, not a new read of the latest working copy.

Focused development plan: first prove red sealed-Node publication change and interleaved original/saved pairing regressions; then correct only listener metadata composition, inventory the closed comparator consumer and update the field-read/audit specs. Retain existing best-effort exceptions, account-context attribution and WeakMap is_new pairing. Verify the owning listener suite and its architecture discriminator; the application integrator separately qualifies the exact adopted package against real SQLite publication and browser audit evidence. An independent reviewer must assess the immutable candidate before adoption.

Observed local evidence: the new tests failed twice against unchanged listener; with the correction all 18 listener tests passed with 44 assertions on PHP8.5.11/PHPUnit11.5.56. An explicit external bootstrap loaded the producer listener before the consumer's installed dependencies. This is narrow source-contract evidence, not a claim that a package was published, installed in the application, staged or deployed.

Owner: audit listener worker in Sprint1 integration. Exact package adoption must pin reviewed producer/split provenance and regenerate consumer main-lane records. Dependency/compiled-state changes require the reviewed upgrade path; an old media recovery exception does not apply.
