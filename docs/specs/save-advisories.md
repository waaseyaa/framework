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

## 10. Public compatibility promise for the draft-mutation seam

The draft-mutation seam is a **public** extension point in
`docs/public-surface-map.php`. Four elements carry that classification together,
and they must stay consistent: a public entry point may not require a consumer
to implement an internal contract.

| Element | Kind | Disposition |
|---|---|---|
| `ContentDraftMutationInterface` | interface consumers implement | public |
| `AdvisoryAwareContentDraftMutationInterface` | extending interface consumers opt into | public |
| `SaveAdvisoryAcknowledgementDispatcher` | entry point consumers call | public |
| `UnsupportedSaveAdvisoryAcknowledgementException` | typed refusal consumers catch | public |

The promise, binding under charter §3.1 (a public-surface break needs a
deprecation cycle, a shim or an argued infeasibility, an upgrade-guide entry,
and a `## Breaking changes` release listing):

1. **The base `updateDraft()` is frozen at five parameters** —
   `(AuthorizationPrincipalInterface $actor, string $id, array $values, int $expectedRevisionId, string $idempotencyKey)`.
   PHP checks an implementing method against every parameter its interface
   declares, so adding even a trailing optional parameter is a load-time fatal
   for existing implementors. It is a breaking change, not an additive one.
2. **Acknowledgement support is opt-in through the extending interface.**
   Implementing `AdvisoryAwareContentDraftMutationInterface` is how a surface
   declares it can carry receipts. Not implementing it stays fully supported.
3. **Callers use the dispatcher and never silently discard receipts.**
   `SaveAdvisoryAcknowledgementDispatcher::updateDraft()` calls the ordinary
   five-argument method when no receipts are supplied and requires the extension
   when they are; otherwise it throws
   `UnsupportedSaveAdvisoryAcknowledgementException` (`SAVE_ADVISORY_UNSUPPORTED`)
   before any write. Dropping receipts to make a call succeed would silently
   re-raise an advisory the caller already reviewed, and is forbidden.
4. **Future capability additions require a new extending interface or a value
   object** — never mutation of an existing implementor's signature. If a
   capability needs more than one new parameter, introduce a parameter object
   rather than a second extension.

`DraftMutationPublicSurfaceTest` enforces the classifications and the exact
method shapes. Revision history and preview seams
(`ContentRevisionHistoryInterface`, `ContentRevisionPreviewInterface`) remain
internal: no public entry point takes them as a parameter.

## 11. The layout-draft seam

A page-builder layout edit is an ordinary entity save. It reaches the repository
save boundary, so a pre-save policy can hold it for review over a field the edit
never touched: an application that raises an advisory on `slug` will raise it on
a layout-only edit to a page whose slug is already reserved. Before #2473 the
layout seam had no receipt channel, so that advisory repeated forever and the
page could not be edited in the page builder at all.

The fix is §10's split applied one seam further out. Nothing about the advisory
itself changes; only the route a receipt travels.

| Element | Kind | Disposition |
|---|---|---|
| `Waaseyaa\PageBuilder\Draft\LayoutDraftGatewayInterface` | interface consumers implement | public, **frozen at five parameters** |
| `Waaseyaa\PageBuilder\Draft\AdvisoryAwareLayoutDraftGatewayInterface` | extending interface consumers opt into | public |
| `Waaseyaa\PageBuilder\Draft\LayoutSaveAdvisoryAcknowledgementDispatcher` | entry point consumers call | public |
| `Waaseyaa\PageBuilder\Draft\Exception\LayoutSaveAdvisoryException` | typed review outcome consumers catch | public |
| `Waaseyaa\PageBuilder\Draft\Exception\UnsupportedLayoutSaveAdvisoryAcknowledgementException` | typed refusal consumers catch | public |

The same four promises in §10 bind here, with the base signature being
`update(AuthorizationPrincipalInterface $actor, string $entityId, string $encodedLayout, int $expectedRevisionId, string $idempotencyKey)`.
`LayoutDraftPublicSurfaceTest` enforces the classifications and the exact shapes.

### Why the layout seam owns its own exceptions

`waaseyaa/admin-surface` does not depend on `waaseyaa/publishing`, and a layout
gateway is not required to be publishing-backed. A page-builder transport
therefore catches only layout-contract types. **Every outcome a publishing-backed
gateway can produce must be expressed in those types**, or it escapes the host
uncaught and the promised structured response never reaches the client.

`PublishingLayoutDraftGateway` translates both advisory outcomes, exactly as it
already translates authorization and not-found outcomes across that boundary:

| Raised in `waaseyaa/publishing` | Translated to |
|---|---|
| `ContentSaveAdvisoryException` | `LayoutSaveAdvisoryException` (payloads verbatim, so the caller receives the same candidate-bound token the entity-storage gate issued) |
| `UnsupportedSaveAdvisoryAcknowledgementException` | `UnsupportedLayoutSaveAdvisoryAcknowledgementException` |

The second case is easy to miss: it arises when the *gateway* advertises
`AdvisoryAwareLayoutDraftGatewayInterface` but the *publisher* it wraps is frozen
at five arguments, so the refusal is raised one seam further in than the one the
transport knows about. The originating exception is chained for diagnosis, and
nothing from it reaches a transport payload.

### The full receipt path

```
GenericPageBuilderSurfaceHost   save_advisory_acknowledgements (optional body key,
                                bounded to 32 lowercase 64-hex tokens or 400)
  -> PageBuilderSurface::apply()          trailing optional parameter (final class)
  -> LayoutDraftManager::apply()          trailing optional parameter (final class)
  -> LayoutSaveAdvisoryAcknowledgementDispatcher::update()   fail-closed decision point
  -> AdvisoryAwareLayoutDraftGatewayInterface::update()      opt-in extension
  -> SaveAdvisoryAcknowledgementDispatcher::updateDraft()    fail-closed decision point
  -> AdvisoryAwareContentDraftMutationInterface::updateDraft()
  -> SaveContext::withSaveAdvisoryAcknowledgements()
```

`LayoutDraftManager` and `PageBuilderSurface` are `final`, so a trailing optional
parameter there is source-compatible: no consumer can be extending them. Only
the two gateway interfaces needed the extension treatment.

### Transport

`GenericPageBuilderSurfaceHost` accepts `save_advisory_acknowledgements` as an
**optional** body key on the command endpoint; the required-key set is unchanged,
so an existing client is unaffected. Malformed receipts are a `400` at the wire
before any surface call. A held edit answers `428 Precondition Required` with
`code: SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED` and the allowlisted
`meta.save_advisories` projection already used by the entity save path, so a
client branches on one machine code regardless of which surface raised it.
Receipts sent to a gateway that cannot carry them answer `501` with
`code: SAVE_ADVISORY_UNSUPPORTED` and no token in the payload.

### The editor affordance (#2475)

The Admin SPA renders the review. `usePageBuilder` holds the exact pending
command with the advisories that candidate produced; the workspace shows each
advisory's `field` and `message`, never its token, and blocks further editing
while the review is open. Confirming replays that same command with exactly the
received acknowledgements, under the **same idempotency key** — a review chain
is one save attempt, exactly as the tests in this section hold one key across a
held attempt, a refused replay, and the successful retry. Declining writes
nothing and leaves the edit dirty and intact. A `501` is presented as a configuration problem with no confirm
affordance. See `docs/specs/admin-spa.md`, "Layout save-advisory review in the
editor".
