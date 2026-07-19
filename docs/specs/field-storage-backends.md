# Field Storage Backends

## Status and scope

Field storage is an active V2-only extension boundary. There is no V1 backend
interface, provider marker, conformance adapter, raw registrar accessor, or
fallback path.

This contract covers per-field storage reads, writes, deletes, and query-support
probes. Entity field-read authorization is specified separately in
`entity-field-read-boundary.md`; storage gateway audit does not grant an
application permission to read an entity field.

## Backend contract

An implementation exposes only:

```php
interface FieldStorageBackendV2Interface
{
    public function id(): string;
    public function fingerprint(): string;
    public function invoke(
        FieldStorageGatewayRole $gateway,
        FieldStorageGatewayInput $input,
    ): FieldStorageGatewayOutput;
}
```

The fingerprint is a reviewed, frozen, lowercase SHA-256 value covering the
implementation and configuration that determine backend behavior. Changing
that behavior requires a deliberate fingerprint change and review.

The input, output, role, invocation, and audit-receipt objects are opaque,
non-serializable, and bound to the exact registrar/backend object identities.
The backend unwraps an input through the supplied role and completes it through
that same role. It never receives a reusable authority or a raw registrar
handle.

## Registration and composition

Providers implement `HasFieldStorageBackendsV2Interface` and return reviewed V2
instances from `fieldStorageBackendsV2()`. Framework providers that claim
`ReservedBackendIds` additionally implement
`IsFrameworkBackendProviderV2Interface` and must appear in the registrar's
explicit framework-provider allowlist.

`BackendRegistrar::build()` requires a
`StrictFieldStorageGatewayAuditInterface` whenever any backend is activated.
It rejects duplicate ids, duplicate implementation/fingerprint identities,
malformed fingerprints, and third-party claims on reserved ids. Callers can
obtain only `FieldStorageBackendGateway`; `get()` and `all()` do not exist.

`buildPreflightInventory()` validates ids and fingerprints without issuing a
gateway role. The activation preflight has no V1 field-backend inventory because
V1 field storage is not a loadable framework surface.

## Invocation and audit order

For every read, write, delete, or query-support probe, the gateway:

1. constructs a value-free attempt descriptor;
2. durably reserves the attempt through the strict audit binding;
3. validates the frozen fingerprint;
4. issues one opaque input and invokes the backend;
5. validates the boundary-bound output; and
6. durably finalizes success or failure.

If reservation or fingerprint validation fails, backend invocation has not
started. A failure after invocation begins records that fact; storage remains
non-transactional across a multi-backend fan-out, and
`PartialSaveException` remains the reconciliation contract.

The durable descriptor contains backend id, fingerprint, operation, entity type
and identifier, and field name. It never contains the field value.

## Framework routing

`BackendResolver`, `DefinitionValidator`, `EntityStorageCoordinator`, and
`CoordinatorLifecycleDispatcher` use registrar-owned gateways. Save callbacks
run before persistence values are captured. The dispatcher then obtains the
post-callback values through a private `EntityBase`-bound persistence authority,
materializes them once, and sends only each backend's declared field value to
the gateway. The value bag is never passed to an event, provider, or backend.

The built-in `sql-blob` and `sql-column` implementations directly implement V2
with frozen fingerprints. `sql-blob` stores data-routed fields in `_data`;
`sql-column` stores supported fields in dedicated columns and supports query
probes for non-vector types.

## Performance contract

Registration, provider discovery, and fingerprint validation occur at boot.
The steady-state operation cost is one gateway lookup per backend group and one
strict reserve/finalize pair plus opaque invocation per field operation. The
coordinator snapshots persistence values once per save, not once per field.
Any optimization must preserve synchronous reservation before invocation and
must not expose or cache raw backend implementations.

## Extension migration

An extension backend must implement V2 directly, freeze its fingerprint, expose
it from a V2 provider, and supply deployment migrations before activation.
There is intentionally no V1-to-V2 adapter. A deployment must not activate a
consumer until every provider in its resolved package set is V2 and the strict
audit ledger is available.
