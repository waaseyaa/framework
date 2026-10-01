# Per-site management conformance

FW-AGENT-MANAGEMENT-01, version 1. Source candidate only; not published in alpha302.

## Authority and adoption

`.waaseyaa/site.yaml` continues to own application identity and active/planned/
excluded capabilities. The optional developer-owned `.waaseyaa/management.json`
companion describes how supported management operations are exposed outside a
browser. It neither grants access nor activates packages, tools, routes or jobs.
Existing sites without the companion retain their existing doctor behavior; a
legacy doctor pass is not a management-conformance claim.

The structural authority is `waaseyaa/site-contract`, Layer 0. It reuses
ManifestShapeReader, CanonicalJson and SiteDoctorFinding, depends on the already
installed Symfony YAML parser, and imports no runtime, domain, database, HTTP,
CLI or MCP package. There is no new execution engine or operation registry.

## Companion format

The root has exactly `schema`, `version`, `application` and `operations`:

```json
{
  "schema": "waaseyaa.management",
  "version": 1,
  "application": "example-site",
  "operations": [
    {"id": "forms.delete", "state": "unsupported", "reason": "No supported deletion operation."},
    {"id": "collection.schedule", "state": "planned", "reason": "Operator-only; no remote schedule capability."}
  ]
}
```

This is an illustrative identity, not an existing application's manifest. Schema:
`packages/site-contract/resources/management.schema.json`. Operation IDs must be
unique. JSON must be unambiguous, at most 1 MiB and at most 64 decoder levels deep.
Object/list distinctions in embedded schemas are preserved. Unknown keys, duplicate
mapping keys, unsupported versions and unsupported values fail closed.

A `supported` entry has exactly `id`, `state` and `contract`. Its contract has:

| Field | Meaning |
| --- | --- |
| `capability` | Exact active capability ID in the owning site manifest |
| `binding` | `{transport: api|mcp|cli, name: <actual binding identity>}` |
| `input_schema`, `output_schema` | Draft 2020-12 object schemas from the real adapter |
| `required_scopes` | Nonempty unique operation permissions; never credential values |
| `tenant_scope` | `required` or explicitly privileged `system` boundary |
| `effects` | Nonempty set of `read/create/update/delete/publish/execute/provision/revoke` |
| `idempotency` | `key`, `natural`, or `none` |
| `concurrency` | `etag`, `revision`, `generation`, or `none` |
| `dry_run` | `supported`, `unsupported`, or `not_applicable` |
| `audit` | `durable`, `best_effort`, or `none` |
| `approval` | `required` or `not_required` |
| `verification` | Nonempty stable check/journey IDs required for this operation |

This parser validates the companion's closed structure, schema envelope and
identity. It does not compile embedded JSON Schema or enforce business semantics.
The operation's existing validator does that. `none` and `unsupported` are truthful
declarations, not features implemented by the manifest. Conformance is a fidelity
check, not a universal security-policy approval or authorization assertion.

Bindings are descriptive identifiers only. They are never executed as shell
commands, fetched as URLs or interpreted as SQL. Public discovery should expose
only an authenticated, appropriately redacted projection; this change installs
no network discovery route. Existing MCP tools/list and OpenAPI remain authoritative.

## Runtime comparison and verification

`ManagementManifestParser::parse()` returns a typed ManagementManifest.
`operation()` validates a ManagementOperation projection from an actual adapter.
`ManagementConformance::inspect($management, $site, $sourceDigest, $inventory)`
returns existing SiteDoctorFinding objects and never dispatches operations.

Product adapters implement ManagementInventoryInterface:

```php
public function sourceDigest(): string;
public function siteManifestDigest(): string;
/** @return iterable<ManagementOperation> */
public function operations(): iterable;
/** @return iterable<ManagementVerificationResult> */
public function verificationResults(): iterable;
```

Use the actual resolved, activated operation catalogue, schemas and permission
metadata. Do not load the companion as the runtime inventory or supply an authored
snapshot file as proof. `sourceDigest` is the complete management input identity from CLI
`ManagementInputDiscovery::digest($projectRoot)`; the site identity is
SiteManifest::digest. It is deliberately separate from ProjectSourceDiscovery's
architecture-scanning selection and from the report's `source_sha256`.

The management identity includes every regular file and directory below the
canonical project root: all languages, SQL, root tests, extensionless scripts,
hidden configuration, dependency lockfiles, installed dependency bytes and modes.
Only `.git` metadata is excluded. Its v1 domain-separated SHA-256 hashes sorted
JSON entry records of relative path, file/directory type, POSIX permission bits
and file SHA-256; the root directory mode is included. No file contents or secret
values are returned. The input tree must be stable throughout verification and
inspection. Store receipts, logs and other verification outputs outside that tree.
Runtime writes inside it conservatively invalidate previous receipts.

Unreadable entries, symlinks and nonregular entries refuse input identity rather
than disappearing. Composer path-repository symlink trees cannot qualify this
profile; use a regular installed/packaged input tree. Permission bits are host
inputs, so cross-host equality is not promised. This identity neither signs a
receipt nor attests that a deployment used these bytes. Verification results must come from executed
checks against those identities and name operation ID, verification ID, exact
ManagementOperation::digest(), passed/failed and a non-secret evidence reference.
The checker binds and compares evidence; it does not sign evidence, independently
execute tests, attest deployment, or defend against a dishonest trusted adapter.

Named checks must cover the relevant allowed/refused behavior, tenant/grant
boundaries, observable outcomes and recovery. A test name, stale pass or source
lint is not an end-to-end journey. Keep browser integration, local adapter tests
and production acceptance as separate verification IDs.

## Doctor wiring

SiteDoctorService accepts an optional ManagementInventoryInterface constructor
argument. Application-owned verification can construct `new SiteDoctorService($inventory)`
and call `inspect()`/`inspectUnits()` without changing the common diagnostic pipeline.
The existing boot-free `site:doctor --strict` does not fabricate a runtime inventory:
an adopted companion is unverified there until the product supplies one through its
verification composition. There is no JSON snapshot argument or dynamic plugin loading.

Doctor reads only a regular local companion, rejects redirected/symlinked targets,
records its byte SHA-256 as optional `management_sha256` and complete `management_input_sha256`, and adds conformance findings
after ordinary suppression processing. A suppression cannot turn absent management
evidence into a pass. Companion bytes do not enter the existing generation plan or
change the site.yaml schema/generator feature contract.

| Diagnostic | Condition |
| --- | --- |
| SITE030 | Invalid, unsafe or unreadable companion |
| SITE031 | Unsupported management version (parser violation) |
| SITE032 | Invalid operation schema envelope (parser violation) |
| SITE033 | Companion/application mismatch |
| SITE034 | Supported operation's site capability is not active |
| SITE035 | No actual runtime inventory supplied |
| SITE036 | Inventory source or site activation identity differs |
| SITE037 | Invalid, duplicate or unavailable inventory/results |
| SITE038 | Registered operation is undeclared/planned/unsupported |
| SITE039 | Supported operation is not registered/visible |
| SITE040 | Actual contract differs, including permissions and safety metadata |
| SITE041 | Required verification missing, failed or bound to another contract |

The optional CLI ToolRegistryManagementInventory projects an actual effective
ToolRegistryInterface. It derives visibility, binding, schemas, capability scope
and dry-run support from registered AgentTool values; product-owned policies supply
domain IDs/effects, ownership and retry/concurrency semantics. Tier audit/approval
configuration is supplied by the actual product composition. Policies cannot
override registry-derived metadata. Idempotency and required key/revision inputs
are cross-checked. No tool is registered or executed by this adapter. Filtered-out
tools remain absent. Missing result schemas or product policies refuse comparison.

## First consumers and evidence boundaries

GoFormX's existing API already completes management without a browser. The contact
lane reports publication/readback, synthetic stored-inbox acceptance and same-ID
replay using existing credentials. Model form creation, schema publication,
organization-scoped inbox read and public submission replay as separate operations
and checks; FETDER browser integration remains separate and pending. Keep Draft
2020-12 schemas, forms:publish and submissions:read boundaries product-owned.

Northway local collection adapter 1f83579 exposes collection.seed (`collection:seed`),
collection.status (`collection:status`) and collection.observations
(`collection:observations:read`). Bind to POST /v1/collection/seeds,
GET /v1/collection/status and GET /v1/collection/observations. Its opt-in flag,
tenant-bound expiring/revocable grants, fenced mutation and cursor schema belong
to NorthCloud. Disabled/unapproved seed creation is not approval or acquisition.
Do not declare collection scheduling, arbitrary crawling or remote grant issuance
supported. Local product checks do not establish deployment activation or grants.

This candidate changes no product files. Product consumers should adopt only after
independent review and a qualified supported package cohort. Published alpha302
does not contain these new classes; importing them from an application currently
pinned to alpha302 requires an explicitly qualified candidate/published upgrade,
not a vendor patch or silent floating-main dependency.
Verification result identities include mandatory sourceDigest and siteManifestDigest; updating inventory identity cannot revalidate an old passing check.
