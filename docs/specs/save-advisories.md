# Save advisories and candidate-bound acknowledgement

Status: implemented on the `S1-FW-ADV-01` review branch

Issue mirror: `waaseyaa/framework#2467`

## 1. Purpose

Applications sometimes need to permit a save while making the caller review a
specific consequence first. A route-backed page slug is the motivating case:
the content row remains valid and editable, but its public fallback URL differs
from the route that owns the short slug. Treating that state as validation
would make intentional legacy content unsaveable; silently accepting it would
hide an operator-significant consequence.

A **save advisory** is therefore a pre-write warning. It is distinct from a
validation error, authorization decision, workflow transition, optimistic
concurrency check, and infrastructure failure. The first attempt returns a
typed acknowledgement-required outcome and performs no backend write. A caller
may resubmit the same candidate with the exact acknowledgement token. Changing
the bound candidate invalidates that token.

## 2. Ownership and extension point

Framework owns the advisory DTO, deterministic token, acknowledgement gate,
typed exception, `SaveContext` acknowledgement set, and transport propagation.
An application owns the policy and message. It registers one listener on the
existing `BeforeSaveEvent` seam and may scope its policy by entity type,
bundle, candidate fields, original entity, and `SaveContext::isImport`.

`BeforeSaveEvent` additively exposes `originalEntity(): ?EntityInterface`.
Create supplies `null`; update supplies the stored entity loaded by the
repository before any write. The event's `entity()` remains the candidate.
This lets policy distinguish an unchanged legacy value from a newly introduced
collision without an application-owned controller, repository, or migration
destination.

Framework supplies the advisory DTO, gate, typed exception, and transport
projection. It does not ship a first-party `SaveAdvisory` producer: the
accepted use case is an application `BeforeSaveEvent` listener (Sheguiandah
issue `jonesrussell/sheguiandah-waaseyaa#111` will own the reserved-slug
roster). Inventing a Framework page-slug policy would collapse that product
decision into the substrate.

The application listener constructs zero or more `SaveAdvisory` values and
passes them with the event's context to `SaveAdvisoryGate::requireAcknowledged()`.
The gate returns normally when the list is empty or every token is present. It
otherwise throws `SaveAdvisoryAcknowledgementRequiredException`, a sibling
`RuntimeException` of `AbortOperationException` rather than a subclass.
Throwing from `BeforeSaveEvent` still aborts the repository transaction before
a backend write and prevents `AfterSaveEvent`. Existing
`catch (AbortOperationException)` blocks keep their prior semantics and do not
silently absorb an unacknowledged advisory. Callers that must present the
review contract catch the typed advisory exception at the intended boundary
(JSON:API, Generic Admin, publishing, migration).

## 3. Advisory value

An advisory contains:

- `code`: stable application-owned identifier matching
  `^[A-Z][A-Z0-9_]{2,127}$`;
- `field`: declared candidate field matching
  `^[A-Za-z_][A-Za-z0-9_.-]{0,127}$`;
- `severity`: the closed value `warning` in this version;
- `message`: non-empty UTF-8 data, at most 1,000 bytes;
- `acknowledgement`: a deterministic lowercase 64-character SHA-256 token.

The constructor rejects malformed or overlong data and never normalizes a
caller-controlled code or field into a different identifier. Codes and
messages are serialized as data only.

### 3.1 Candidate binding

`SaveAdvisory::forEntityField()` derives the token from a domain-separated
canonical form containing:

1. contract version;
2. entity type and bundle;
3. stable entity identity (the literal `new` for creates, otherwise `uuid`, then id);
4. advisory code and field;
5. the candidate field value encoded with type preservation.

The token is `sha256` over that canonical form. It is a review receipt, not a
secret and not authorization. The raw candidate value is never included in the
wire advisory unless the application deliberately puts it in the message.
Changing the warned field, entity, bundle, or advisory identity yields another
token. The message is deliberately not bound: copy edits do not invalidate an
otherwise identical policy decision, while code changes do.

Canonical values admit null, bool, int, finite float, string, and recursively
key-sorted arrays of those values. Resources, closures, objects, non-finite
floats, excessive depth, and invalid UTF-8 fail closed before token creation.

## 4. SaveContext

`SaveContext::withSaveAdvisoryAcknowledgements(array $tokens)` validates a
list of exact lowercase 64-hex tokens, deduplicates and sorts it, and returns a
new context. Every existing context builder preserves the set.

`saveAdvisoryAcknowledgements()` returns the canonical list and
`acknowledgesSaveAdvisory(string $token)` performs an exact constant-time
membership comparison. Unknown tokens grant nothing. They may be carried to a
save, but only a token emitted for the current candidate can satisfy a gate.

## 5. JSON:API and Generic Admin

JSON:API create and update accept an optional resource-object member:

```json
{
  "data": {
    "type": "node",
    "attributes": {},
    "meta": {
      "save_advisory_acknowledgements": ["0123...64 lowercase hex..."]
    }
  }
}
```

An absent member means an empty set. A non-list, non-string element,
malformed token, or oversized list is a `400 Bad Request`; malformed input
never reaches repository save. The bounded maximum is 32 tokens.

An unacknowledged advisory maps to HTTP `428 Precondition Required` with one
JSON:API error object:

```json
{
  "status": "428",
  "title": "Precondition Required",
  "code": "SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED",
  "detail": "Review and acknowledge the save advisory before retrying.",
  "meta": {
    "save_advisories": [
      {
        "code": "RESERVED_PAGE_SLUG",
        "field": "slug",
        "severity": "warning",
        "message": "The short route is reserved; this page remains at /pages/news.",
        "acknowledgement": "0123..."
      }
    ]
  }
}
```

Ordering is deterministic by code, field, then token. The controller catches
the typed exception on create, plain update, and expectation-stated update.
Existing validation remains a `422` and has no acknowledgement token.

Generic Admin already delegates create/update to JSON:API. It projects the
JSON:API error into the Admin envelope through
`AdminSurfaceResultData::fromJsonApiError()`: status, title, and detail remain
the legacy error shape; `code` and `error.meta` are emitted only for
`SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED`, and `meta` contains only the
allowlisted `save_advisories` fields (`code`, `field`, `severity`, `message`,
`acknowledgement`). Policy reasons, internal identifiers, tokens, nested
objects, and other JSON:API error.meta keys do not cross the Admin boundary.
A missing mutation-token HTTP 428 remains a codeless/meta-less precondition
error, so the schema form can distinguish it from an unacknowledged advisory
without parsing prose. The SPA `TransportError` carries that same closed
`AdminSurfaceErrorMeta` type; it does not reopen error metadata as
`Record<string, unknown>`.

The Admin transport supports optional acknowledgement tokens on create/update
payloads. The schema form displays the returned advisories in an accessible
`role=status` review panel with one explicit confirmation button. Confirmation
resubmits the exact captured writable candidate with only the returned tokens.
Editing any field, changing bundle scope, or starting another submit clears
the pending review. The ordinary Save button never implies acknowledgement.

## 6. Publishing and MCP

`ContentPublisher::createDraft()` and `updateDraft()` accept a trailing optional
list of acknowledgement tokens. The tokens are included in the idempotency
request fingerprint and applied to the same `SaveContext` that carries actor
and revision expectations.

`ContentDraftMutationInterface` is frozen at its original five-parameter
`updateDraft()` shape. Applications implement that seam directly, and PHP
requires an implementor to declare every parameter its interface declares, so
adding even an optional parameter to it is a load-time fatal for every existing
implementor. Acknowledgement support therefore lives on
`AdvisoryAwareContentDraftMutationInterface`, which extends the frozen contract
and redeclares `updateDraft()` with the trailing optional token list.
`ContentPublisher` implements the extended interface.

Callers must not branch on the concrete type. `SaveAdvisoryAcknowledgementDispatcher::updateDraft()`
is the single decision point: with no tokens it calls the ordinary five-argument
method, so a legacy implementor is unaffected; with tokens it requires the
extended interface and otherwise throws
`UnsupportedSaveAdvisoryAcknowledgementException` (code
`SAVE_ADVISORY_UNSUPPORTED`) before any write. Receipts are never dropped to
make a call succeed, and the refusal carries no token, policy, or implementation
detail. The storage exception maps to
`ContentSaveAdvisoryException`, a structured `ContentPublishingException` with
code `SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED` and advisory data in `meta`.

The canonical MCP content tools expose the optional
`save_advisory_acknowledgements` array on create/update. Their existing
structured error envelope carries the publishing exception unchanged. A tool
client therefore reviews `meta.save_advisories` and retries the same input with
the returned tokens; it does not parse prose.

Publication transitions and revision rollback do not gain an acknowledgement
parameter in this slice. They do not mutate the application field that
motivates the policy, and transition-owned repository orchestration needs a
separate context contract if an application later declares advisories over
those operations.

## 7. Migration

`MigrationDefinition` declares
`acknowledgedSaveAdvisoryCodes: list<string>`; default empty. This is trusted,
version-controlled operator policy, not source-record input. The same code
syntax and a maximum of 32 unique entries are enforced.

For the canonical `EntityDestination`, the runner applies the declaration via
an immutable clone before processing records. Each create or changed update:

1. performs the ordinary import save with no generated acknowledgements;
2. if the app listener raises the typed exception, compares every returned
   advisory code against the migration declaration;
3. if any code is undeclared, raises a typed `DestinationWriteException`; the
   encompassing entity/id-map transaction rolls back and no write occurs;
4. if all codes are declared, retries the same in-memory candidate inside the
   same transaction with exactly the returned candidate-bound tokens;
5. returns deterministic acknowledged advisory evidence with the successful
   `WriteResult` and emits it into the bounded `RunReport` warning list.

The retry is limited to one. A changed or nondeterministic policy therefore
fails closed rather than looping. The evidence record contains migration id,
source-id hash, code, field, severity, message, and token, sorted
deterministically. It contains no raw source value. Hash-match skips perform no
save and produce no new warning evidence.

Third-party destinations do not receive implicit acknowledgement behavior.

## 8. Invariants

1. A first unacknowledged attempt performs no backend write.
2. Acknowledgement never bypasses validation, access, workflow, concurrency,
   mutation authority, audit, or repository lifecycle hooks.
3. A validation error has no token and cannot be acknowledged.
4. A token suppresses only the exact entity/advisory/field/candidate tuple that
   produced it.
5. Original entity state comes from repository storage, never request data.
6. Import acknowledgement is explicit in the migration definition and visible
   in run evidence.
7. Application policy is registered once at `BeforeSaveEvent`; no application
   shadows Framework controllers, publishers, or migration destinations.
8. Advisory payloads are bounded, deterministic, and serialized only as data.

## 9. Compatibility

All new PHP parameters are trailing and optional, which makes them safe for
**callers**. It does not make them safe for **implementors**: PHP checks an
implementing method against every parameter its interface declares, so a
trailing optional parameter added to a published interface is still a
load-time fatal for code that already implements it. Interfaces that
applications implement are therefore frozen, and new capability arrives as an
extending sub-interface — see §6 for `AdvisoryAwareContentDraftMutationInterface`.

`BeforeSaveEvent`'s original constructor shape remains valid because the
original entity is trailing and nullable, and it is constructed by Framework
rather than implemented by applications. Existing `SaveContext`, JSON:API,
Admin transport, publishing, MCP, and migration callers retain their previous
behavior when they provide no advisory policy or acknowledgement data. Existing
response bodies remain unchanged outside the new typed failure path.
