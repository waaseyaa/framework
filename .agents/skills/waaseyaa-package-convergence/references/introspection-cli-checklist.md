# Introspection and CLI checks

Use for packages describing application behavior or adapting it to commands and AI tools.

## Truthful descriptions

- Trace descriptions back to the actual composed application, including provider-defined entries and ordering. A builtins-only or empty fixture cannot prove consumer completeness.
- Distinguish empty, unavailable, failed and complete sections. Verify what the output itself tells a consumer; a warning in a separate log may not make a successful partial result unambiguous.
- Compare declared access metadata with effective restrictions. Exercise conflicting flags and layered enforcement; a public route flag alone does not establish unrestricted entity access.
- Check description lifetime against the producer's lifecycle. Identify stale snapshots and early construction, and separate stable definitions from request-bound values.
- Inspect object-valued metadata before treating it as a serializable definition. A cloned container or route may still share nested live controllers/services. Use bounded descriptive representations for inspection, preserve executable values for runtime dispatch, and test that JSON does not traverse arbitrary object state.

## Command boundary

- Trace discovery, handler construction, dependency resolution, execution, serialization and output. Test failure at construction as well as execution; handler-local catches cannot cover failures before invocation.
- Establish whether section/filter selection scopes work or only output. Test unrelated unavailable capabilities against the promised selection semantics.
- Round-trip nonempty JSON through the real output adapter, including formatter-like strings. Check stdout/stderr, exit status, optional integrations, and metadata retained when commands are rebound to a container.
- Test supported strict/tolerant modes, unavailable dependencies, unknown selections and repeated execution. Make determinism claims match the ordering actually controlled.
- Trace AI/MCP consumers separately: making a service constructible may affect discovery, so verify capability and exposure boundaries without enabling unrelated access.

## Installed consumer proof

Qualify through the supported executable and real composition root with individual installed dependencies and an application-defined entry. Include absence/failure controls for optional integrations. Distinguish root-metapackage autoloading, stub-provider tests, path repositories and published artifacts; each proves a different installation boundary.
