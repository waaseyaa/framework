# External Extension SDK

## Purpose

Define deterministic authoring contracts for external Waaseyaa extension modules.

## CLI Scaffold Contract

Command:

- `scaffold:extension`

Required options:

- `--id`
- `--label`
- `--package`

Optional options:

- `--namespace` (auto-derived from package when omitted)
- `--class` (default: `KnowledgeExtension`)
- `--description`
- `--workflow-tag`
- `--relationship-type`
- `--discovery-hint`

Validation rules:

- plugin ID: `[a-z][a-z0-9_]*`
- package: lowercase `vendor/package`
- class: PascalCase PHP identifier
- namespace: valid PHP namespace

Output shape:

- root: `extension_sdk`
- sections:
  - `plugin`
  - `package`
  - `contracts`
  - `defaults`
  - `files`

## Files Template Contract

Deterministic template files emitted in payload:

- `README.md`
- `composer.json`
- `src/<Class>.php`

Template contract alignment:

- generated class extends `PluginBase`,
- generated class implements `KnowledgeToolingExtensionInterface`,
- generated class is attribute-registered with `#[WaaseyaaPlugin(...)]`,
- workflow/traversal/discovery context mutators are present.

## Stability

- This is an additive authoring surface.
- Existing scaffold commands and plugin runtime contracts remain unchanged.
