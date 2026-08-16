# Security Policy

## Supported Versions

Waaseyaa is pre-1.0. Only the newest tagged alpha receives fixes, through a
new immutable tag. The preceding three alpha trains receive upgrade guidance,
not backports. Older alphas are end-of-life. See the S1 lifecycle contract.

## Reporting a Vulnerability

Use GitHub private vulnerability reporting for suspected vulnerabilities.
Do not open a public issue containing vulnerability or exploit details.
There is no response-time SLA; maintainers will acknowledge and triage reports
as capacity permits. Security remediation may require the newest alpha.

## System and Scope

This policy covers framework packages, the Admin SPA, HTTP/API/MCP/OIDC
boundaries, CLI/runtime composition, and repository-owned build/release tooling.
Consumer configuration and infrastructure are separate unless the defect
originates in a framework contract or safe default.

## Threat Model and Trust Boundaries

Internet input, authenticated non-administrators, uploaded content, imported
configuration/data, extension code, and persisted rows may be hostile.
Authentication is not authorization. Tenant/community identity, classified
fields, credentials, filesystem paths, database state, process boundaries,
artifacts, and dependency inputs cross explicit trust boundaries.

## Security Invariants

- Authorization and community scope precede protected reads and mutations.
- Public APIs fail closed; privileged bypasses are explicit and auditable.
- Protected, Internal, Secret, and Credential data are not released generically.
- Configuration, schema, migrations, and deployment inputs reject ambiguity or drift.
- Parsers, paths, redirects, network targets, and resource use remain bounded.
- Dependencies, generated artifacts, and release inputs retain verifiable provenance.
- Security-sensitive failures do not silently downgrade controls.

## Reportable Findings and Severity Context

A finding is reportable when a broken invariant has a realistic framework or
supported-profile path. Cross-community access, credential compromise,
authorization bypass, code execution, release compromise, or durable integrity
loss are normally high impact. Severity must reflect demonstrated reachability,
required privileges, affected data, blast radius, and compensating controls.

## Out of Scope and Known Limitations

H1, remote/shared filesystems, MySQL/PostgreSQL, WebKit/Safari, and unlisted
web runtimes are unsupported, but unsupported status does not suppress a flaw
reachable through S1. Upstream-only defects belong upstream unless Waaseyaa
introduces or fails to contain them. The S1 consumer point is not certified
until its separately named evidence is complete. No additional accepted risk
or exclusion may be inferred from this file.
