# Extension Module Packaging and Release Playbook

## Purpose

Define a contract-aware packaging and release workflow for external Waaseyaa extension modules.

## Recommended Package Layout

```text
<extension-package>/
  composer.json
  README.md
  src/
    <ExtensionClass>.php
  docs/
    CHANGELOG.md
    CONTRACT.md
  tests/
    Unit/
```

## Required Composer Metadata

- `name`: `vendor/package`
- `type`: `library`
- `require.php`: `^8.3`
- `require.waaseyaa/plugin`: compatible range for target platform series
- `autoload.psr-4`: extension namespace mapped to `src/`

## Contract Declaration

Each extension release should declare:

- supported Waaseyaa contract range (for example: `>=1.0 <2.0`),
- implemented extension interfaces,
- affected MCP/tooling surfaces,
- compatibility notes for deprecated aliases and renamed options.

Recommended file: `docs/CONTRACT.md`.

## Semantic Versioning Policy

- `MAJOR`: breaking contract changes (removed/renamed stable fields/methods)
- `MINOR`: additive features or diagnostics surfaces
- `PATCH`: bugfixes without contract shape changes

## Release Checklist

1. Run extension unit tests.
2. Run Waaseyaa contract matrix checks against target framework version.
3. Run cross-repo harness for Waaseyaa <-> Minoo integration where applicable.
4. Update changelog and contract declaration docs.
5. Tag and publish release.
6. Record release artifact checksum and release notes link.

## Verification Command Set (Recommended)

- `php bin/waaseyaa scaffold:extension --id <id> --label <label> --package <vendor/package>`
- `./vendor/bin/phpunit --configuration phpunit.xml.dist`
- `tools/integration/run-v1.3-cross-repo-harness.sh`

## Audit Artifacts

Store release evidence alongside each version:

- command outputs,
- contract matrix pass evidence,
- cross-repo harness artifact,
- changelog entry and tag reference.
