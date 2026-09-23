# HTTP, UI and wire contract checks

Use for packages that serve routes, middleware or refusals, or share a payload
contract with another language or client (PHP to TypeScript, an SPA, MCP).
The admin-surface convergence (#3074) is the worked reference.

## Crossings

- Before calling a contract canonical, enumerate every request and response that crosses the boundary, including secondary actions such as schema, history, preview, restore and errors. Trace each from its producer or validator, through transport, to the consuming type. A crossing typed only by a local cast or a consumer-private type is not covered.
- Derive field presence from the actual serialization path. An omitted field, an optional field, a required nullable field and a required non-null field are four different contracts.
- When a field carries authority or lifecycle state, test transitions, not just snapshots: authority becoming null, a capability disappearing, a session ending, an optional service going away. Static types can't prove that stale cached authority is revoked.
- One canonical contract doesn't need one file. Split distinct protocols by concern, with one public entrypoint and one compatibility story.

## Mechanical checks

- Duplicated structural contracts must fail on missing or extra keys, required-versus-optional drift, nullability drift and incompatible values. Plain assignability can let an optional field disappear silently.
- Producer conformance must reject both undeclared emitted keys and missing required keys. Keep producer-to-canonical and canonical-to-consumer checks separate, and don't claim "every payload" unless the roster names each crossing.
- Use non-empty examples for optional and nested payloads. Type browser and integration fixtures against the canonical contract, or validate them mechanically; an untyped mock keeps a false green path alive after the contract changes.
- If conformance parses contract source instead of importing a runtime schema, require module discovery, nested-structure handling and a sanity control. A parser that finds nothing must fail.
- The gate must run when either the canonical definition or any mirror changes. Check workflow path filters, dependency provisioning and the owning required check. Don't add a gate to a job that can't provision its runtime.

## Refusals and authentication

- Prove the whole refusal stack: route options, real middleware, handler not invoked, wire-level status and response shape.
- Separate kernel-level malformed-body refusal from host-level refusal of valid JSON with the wrong shape.
- For a CSRF journey, bootstrap from the real application HTML, read the runtime-configured CSRF cookie name, keep the session cookie jar, and send the matching header on mutation. Also prove a mismatched token can't persist state. Don't hard-code a default cookie name.
- Cover authentication, authorization, field access and information-oracle behavior (does a refusal reveal whether something exists?).
