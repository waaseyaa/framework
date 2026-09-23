# Generation and build checks

Use for packages that generate code, scaffold projects or apply blueprints, or
build artifacts.

- **Deterministic plans:** the same inputs produce the same plan and bytes. Check ordering, timestamps and host-specific paths or line endings.
- **Ownership:** know which generated files the tool owns and which the user owns. Regeneration must not overwrite user-owned edits silently.
- **Collisions:** existing files, names or tables that clash with generated ones are refused or explicitly resolved, never clobbered.
- **Atomic application:** a failed generation leaves no half-written project. Check temp-then-rename and rollback.
- **Generated runtime dependencies:** list every framework symbol that generated code imports or calls at runtime (for example a migration helper). Those symbols are a public contract; moving them breaks historical generated files.
- **Upgrade behavior:** regenerating over an older output, and running old generated code against a newer framework.
- **Execute the output:** run the generated code or artifact, not just snapshot its text.
