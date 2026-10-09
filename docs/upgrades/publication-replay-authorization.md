# Publication replay authorization

Stored publication responses now require the acting principal's current entity
and revision authority when ContentPublisher has an entity access handler.
Applications should handle the existing UNAUTHORIZED and NOT_FOUND refusals on
retries, including after moderation, ownership changes or revision restriction.
Restoring authority permits the original retry without repeating its mutation.

The additional optional IdempotencyStore replay-authorization callback runs only
after a matching stored request fingerprint. Other store consumers retain their
existing operation contract; policy belongs to the caller. Applications should
continue composing the supported entity handler rather than using handler-free
publication as a substitute for entity authorization.

No database migration, retry-key change or response-shape change is required.
Actor-scoped partitions, conflicting payload refusal and original authorized
replay responses are preserved. Local candidates require exact installed-package
consumer qualification; this guide does not establish a published fixed version.
