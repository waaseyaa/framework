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
