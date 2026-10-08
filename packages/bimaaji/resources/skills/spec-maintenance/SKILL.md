---
name: waaseyaa-spec-maintenance
description: Audit and maintain Waaseyaa specifications and agent guidance against intended requirements, code, consumers and acceptance evidence. Use for SDD contract changes, drift repair and scoped retirement of superseded documents.
---

# Specification maintenance

Follow the current repository's guidance. For Framework, `AGENTS.md` routes
subsystems, `docs/governance/agent-contract.md` owns operating rules, and
`docs/specs/workflow.md` owns the design-first workflow. In a consumer project,
its own governance applies; Framework policy does not grant authority there.

## Establish intended behavior

Find the relevant live specs, ADRs, existing issues, tests and consumers before
editing. Use `rg` on capabilities and symbols as well as package names. For
installed applications, match Framework documentation to the locked release.

Trace each changed requirement to one canonical spec section, its real
implementation/consumers and discriminating acceptance evidence. Include
missing implementations, undocumented behavior and contradictory specs. A
canonical label does not prove that prose is current. Resolve intended
behavior before changing code, and never rewrite a requirement solely to
make a defect look compliant.

Substantive Framework work is anchored by a portable change record. GitHub
issues and PRs mirror coordination and evidence; they are not a replacement
for that record. Design and acceptance precede implementation; keep the spec,
caller changes and tests together in the bounded repair.

## Keep the working tree useful

During authorized cleanup, delete superseded specs, duplicate plans and stale
examples after reconciling current obligations. Preserve still-valid
requirements or decision rationale in the live authority and update inbound
links, routing, corpus manifests, generators and tests in the same change.
Git history normally preserves the old text; another archive or redirect stub
is not the default.

Retain historical material only for a named current obligation, such as
supported upgrade guidance or exact audit evidence. Do not rewrite frozen
evidence to suggest it was checked against current code. Migrate the consuming
evidence contract before deleting files it still validates. Record unresolved
retention work with its owner and removal condition.

## Verify the change

Check changed document links, symbols, commands, lifecycle and authority claims.
Run the owning corpus/manifest validators when their inputs change. For code
changes, use the relevant focused acceptance tests and Framework's local
testing policy. The drift detector checks coupling, not semantic truth:

```bash
bash tools/drift-detector.sh 5
```

This command is a POSIX entrypoint. On other hosts, follow the native-host
contract and name the supported runner that owns missing evidence. Do not
claim an unavailable gate passed.
