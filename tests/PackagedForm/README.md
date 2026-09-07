## Packaged-form consumer fixture

`tests/PackagedForm/skeleton/` is a minimal downstream app that installs
published Waaseyaa packages from Packagist and proves the kernel path as a
consumer sees it.

This fixture exists to catch a different failure class than the in-tree
`tests/Integration/` suite:

- `tests/Integration/` runs against the monorepo checkout and path-resolved
  sibling packages.
- `tests/PackagedForm/skeleton/` runs as a standalone consumer with its own
  `composer.json`, provider list, config, and PHPUnit entrypoint.

Rules:

- Do not add path repositories, `../packages/*`, or local override repos.
- Keep the fixture pinned to exact published alpha tags.
- Keep the harness minimal: one consumer provider, one bundle field, one
  kernel-path round-trip.
- Do not add named kernel subclasses under `tests/**`; the fixture harness
  must use the anonymous-subclass + `publicBoot()` pattern.

`check-bimaaji-skill-resources` covers the packaged-resource boundary
(#2656): it builds a consumer from the candidate tree with path
repositories set to `symlink=false`, so `vendor/waaseyaa/bimaaji` holds
installed bytes rather than a link back into the checkout, and drives
`bin/waaseyaa bimaaji:install` from there. It seeds no skill fixtures —
everything the command writes must come from resources shipped inside the
installed package, which is the whole point of the proof. It also asserts
the consumer has no project-root `skills/` directory; Codex and Claude
receive the same skill ids, source hashes, and per-skill bytes; root
`AGENTS.md` stays within its concise-index budget; a second run is a no-op;
hand-authored content around the managed region survives a refresh; and
deleting or corrupting the installed resources yields the matching
missing-vs-corrupt diagnostic.

`check-s1-sqlite-artifact` covers a separate boundary: it seals the exact
candidate `waaseyaa/database-legacy` package, installs the archive into an
isolated consumer `vendor` directory without a path repository, and runs the
S1 SQLite runtime probe from those installed bytes. It is intentionally
offline-capable; the consumer supplies the already locked third-party runtime
dependencies but no Waaseyaa source autoloader. The proof rejects lock versus
installed-metadata drift, binds exact Doctrine/PSR versions and source/dist
references, compares recursive source/copy byte digests, and requires the
resulting canonical dependency manifest to equal the reviewed
`support/s1-sqlite-dependency-bytes.json` authority.

`check-split-artifact-acceptance` covers the packaging boundary (#2649). It
seals the exact commit into a local Composer **artifact** repository — one zip
per member produced by `git archive`, so the bytes are the bytes git would
export — and runs `composer create-project` against it with the repository
declared `only: ["waaseyaa/*"]`, which makes it canonical for those names. The
consumer therefore installs extracted archive bytes with no path repository, no
VCS repository, and no symlink into the checkout. It asserts composition (the
installed set equals the sealed transitive closure of `waaseyaa/framework`
exactly), exported files (every installed tree byte-identical to its archive,
plus the #2543 admin-surface manifests exercised as a pinned fixture through the
consumer procedure in `packages/admin-surface/contract/README.md`), bootstrap,
the development plane (`waaseyaa/ai-development` is sealed, arrives in the dev
consumer as a development dependency, carries the two members ADR-022 D-2
requires, never reaches `waaseyaa/mcp`, and is in no production closure), and
`--no-dev` exclusion — which removes every development-only member — including
a boot of the `--no-dev` consumer. Artifact origin (a zip inside the local
artifact repository) is asserted for every member; what Composer must have
*done* with it is keyed on package type, because a `type: metapackage` is
resolved and never extracted — so it must record no installation-source, a null
install-path, and no vendor directory, while a code-bearing package must record
a `dist` installation. One surface #2649 names is still recorded as reserved in
`fixtures/split-artifact-acceptance-surfaces.json`: stdio initialization
(#2659). The development metapackage was reserved the same way until #2655
landed; its fail-closed hatch fired as designed. Sixteen seeded negative
controls run on every invocation and the run fails if any corruption goes
undetected. It seals `HEAD` and refuses a dirty worktree unless
`--allow-dirty` is passed; Composer 2.9+ is required.

`check-cli-health-report` covers the consumer-DI boundary (#2820). It builds
one disposable `--no-dev` consumer from the candidate tree with path
repositories, requiring only `waaseyaa/core` + `waaseyaa/cli`, and strips the
skeleton's `src/`, its PSR-4 root, and its `extra.waaseyaa.providers`
declaration, so nothing but installed framework bytes can carry a container
binding. After `install:init` it boots the real console runtime and executes
`health:report`, `health:report --json` (stdout must parse as a single JSON
document whose `system."Project Root"` is the consumer's own root — the value
only the framework composition contract can have supplied), and
`health:report --json --output FILE`. The exact defect string
(`Cannot auto-wire … unresolvable parameter`) is rejected by name whatever the
exit code. Like `check-cli-ai-commands-optional`, it archives `HEAD`, so it
proves committed bytes, not the working tree.

`check-studio-alpha-acceptance` covers the Studio-alpha story end to end
(#2789). It reuses `split-artifact-acceptance.php seal` rather than growing a
second sealing authority, then drives ONE disposable artifact-installed
consumer serially through install, verification, seeded real identities (a
privileged one and an unprivileged one through `user:create`, which actually
signs in so the denial it proves is a policy
decision), two separately controlled processes — the installed PHP backend and
the installed `vendor/waaseyaa/admin-surface/dist` bundle served by its own
Node process — a real no-mock Playwright operation against that bundle, the
typed `ENTITY_NOT_FOUND` 404 for a denied and a missing read, hand extension
through `make:content-type`, a snapshot, an interrupted publication recovered
by a later process, an additive upgrade, a zero-write
`GEN011_UNAUTHORIZED_SET_DELTA` refusal, and final restoration plus
verification.

`waaseyaa/admin-surface` ships a PRERENDERED Nuxt build with no Nitro entry, so
serving those installed bytes *is* running the installed admin package; the
harness starts no Nuxt dev server, because doing so would substitute checkout
source for the sealed artifact. Only the Playwright executable comes from the
repository's pinned toolchain (`npm ci --prefix packages/admin`); the spec and
config bytes are the repository's own and no route is mocked. Both processes
take reserved free ports, have their own pid, bounded readiness probe and
retained log, and are torn down by one idempotent root-owned trap.
`WAASEYAA_DEV_FALLBACK_ACCOUNT` must be unset — the harness refuses to start
otherwise, because a fallback identity masks the denial it exists to observe.
Three seeded negative controls run at the end.

`check-cli-sync-rules` covers the package-owned rule boundary (#2832). It
archives the candidate commit and installs copied packages into the accepted
Content Pipeline direct profile (23 runtime and 5 development requirements),
without `waaseyaa/framework`, and an aggregate control. The real CLI must report
the three Foundation rule files and 3/0/0 counts without creating application
rules. Missing and empty installed resources are negative controls against a
vacuously passing proof. The historical failing consumer pin is context only;
the harness qualifies the candidate bytes.
