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
the consumer has no project-root `skills/` directory, that a second run is
a no-op, that hand-authored content around the managed region survives a
refresh, and that deleting or corrupting the installed resources yields
the matching missing-vs-corrupt diagnostic.

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
