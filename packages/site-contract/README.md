# Waaseyaa Site Contract

This Layer 0 package owns the provider-neutral `.waaseyaa/site.yaml` contract:

- a strict versioned schema;
- typed application, framework, content, capability, privacy, recipe, and
  verification declarations;
- an optional, closed `application_blueprint` section (#2785, ADR-023)
  describing a model-independent application proposal — entities and fields,
  relationships, permissions and roles, default-deny policies, workflows,
  fixtures, and generated behavioural checks — under
  `Waaseyaa\SiteContract\Blueprint\`, plus a request-scoped decision receipt
  and lifecycle resolver;
- deterministic YAML parsing and canonical JSON/SHA-256 identity; and
- an explicit version disposition that refuses implicit migration or
  downgrade; and
- deterministic generated-site artifacts with declared extension regions and
  managed digests for transactional publication by higher-layer clients.

It does not own CLI commands, generators, recipes, runtime service wiring,
Git hosting, CI adapters, or deployment behavior. Those are higher-layer
consumers of this package.

## Optional management companion

`Waaseyaa\SiteContract\Management` owns the closed `waaseyaa.management` v1
companion structure, operation identity, inventory seam and read-only conformance.
`resources/management.schema.json` is the machine-readable shape. Embedded Draft
2020-12 schemas and actual registration, scopes, execution and acceptance checks
remain owned by the product adapter. This package grants no permissions, starts
no operations and installs no discovery endpoint.

Verification results bind the exact operation digest, complete management input
digest, site manifest digest and a non-secret reference to an executed check.
The CLI's `ManagementInputDiscovery` supplies complete regular-tree identity;
the architecture scanner's filtered source digest is insufficient. Products
without CLI can implement the same documented identity contract; no CLI runtime
dependency is introduced here. Missing/stale inventory or results fail closed.

Operation metadata may contain nested JSON objects. Treat parsed descriptors as
immutable input by convention: modifying a nested object changes its operation
digest and invalidates old results; it does not rewrite the manifest's original
canonical bytes. Do not reuse modified descriptors as the original declaration.
The package-convergence audit records deeper immutability as a bounded follow-up.

Alpha.302 does not contain this management API. Adopt only a qualified cohort
containing this change. Source tests are distinct from installed-consumer and
product journey qualification. See `docs/specs/agent-management.md` in Framework.
