# S1 configuration authority

Waaseyaa has one production configuration authority. Bootstrap configuration
selects the database, environment, secret references, and sync artifact path;
it is not deployable configuration. Active deployable configuration is one
versioned SQLite generation. The filesystem sync bundle is desired-state input
and output, never a runtime read authority. Secret values are forbidden from
active generations, sync artifacts, manifests, and evidence.

`ConfigurationAuthorityResolver` consumes bootstrap inputs once and publishes
one immutable `ConfigurationAuthorityContext`. The canonical sync selector is
`config.sync_path`, with environment equivalent
`WAASEYAA_CONFIG_SYNC_PATH`; absence resolves to
`<projectRoot>/storage/config-sync`. Transitional `config_dir` and
`WAASEYAA_CONFIG_DIR` aliases are accepted only when every supplied selector
normalizes to the same path. A disagreement fails before provider composition.
Legacy use remains visible in selector provenance so diagnostics and immutable
audit evidence do not need to re-read the environment. Resolving the singleton
context also dispatches one typed `ConfigurationSelectorDeprecationEvent` per
legacy selector, binding its replacement and the non-secret authority ID.

Paths are normalized relative to the project root. Existing symlink segments
are resolved. Escaping the project boundary fails unless bootstrap explicitly
permits an external local sync path. A missing final directory may be planned
but is created only by an authorized mutating command. Resolution records the
nearest existing directory's canonical path, device, and inode. Every sync
operation revalidates that identity before and after use, refuses a final-path
symlink or redirected canonical path, and accepts only regular non-link member
files. If the final directory did not exist during resolution, each supported
adapter pins its device and inode on first authorized use and rejects later
replacement. Writes use an exclusively created, unpredictable same-directory
file, durably flush it, revalidate authority, then atomically rename it into
place.

The context binds database identity, canonical sync path, selector provenance,
and active generation identity. Every supported consumer receives that same
object. Runtime code never constructs `FileStorage(config/active)` or reads the
sync bundle directly. The higher composition bridge owns active-generation
enumeration and mutation; CLI, MCP, diagnostics, and optimize adapters consume
the context and cannot select an alternate store. Production-equivalent and
unknown environments cannot publish the authority capability without an active
generation. Only the explicit `local`, `dev`, `development`, and `testing`
bootstrap profiles may compose before persistent activation.

CFG-01 installs composition and refusal seams. CFG-02 owns transactional
generation activation and compare-and-swap. CFG-03 owns schema, version,
manifest, and drift decisions. CFG-04 owns secret-reference resolution and
rotation. Missing successor bindings fail closed rather than restoring a
filesystem or environment fallback.

Bootstrap keys can neither be exported nor shadowed by an active generation.
`DeployableConfigurationPolicy` rejects bootstrap-owned names and raw
credential-shaped fields at sync-file construction and again while binding a
database generation. Credential-bearing fields contain opaque environment or
custody references only; resolution remains CFG-04's responsibility.

The retained proof suite snapshots bootstrap, active SQLite, sync-artifact and
compiled-derived bytes around status, diff, validate and dry-run import. It
also builds reproducible local archives for every split package from the exact
candidate tree, installs those extracted archives into an isolated CLI/core
consumer, and proves exactly one adapter for each configuration command. No
forge or package publication participates in that proof.
