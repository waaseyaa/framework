# Waaseyaa Site Contract

This Layer 0 package owns the provider-neutral `.waaseyaa/site.yaml` contract:

- a strict versioned schema;
- typed application, framework, content, capability, privacy, recipe, and
  verification declarations;
- deterministic YAML parsing and canonical JSON/SHA-256 identity; and
- an explicit version disposition that refuses implicit migration or
  downgrade; and
- deterministic generated-site artifacts with declared extension regions and
  managed digests for transactional publication by higher-layer clients.

It does not own CLI commands, generators, recipes, runtime service wiring,
Git hosting, CI adapters, or deployment behavior. Those are higher-layer
consumers of this package.
