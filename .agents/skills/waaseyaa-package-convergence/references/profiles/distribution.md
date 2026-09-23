# Distribution checks

Apply to every package's split form, and in depth to metapackages, packages
with committed build output, and anything consumers install without dev
dependencies.

## Installed form

- Identify the supported installation profiles: primitive-only use, split package, kernel composition, curated metapackage (`core`, `cms`, `full`), generated application.
- Install the split package on its own and boot it with `--no-dev`. Nothing under `src/` may extend a dev-only class.
- Check optional dependencies both absent and present. Absence must not change unrelated behavior.
- Distinguish what each test proves: root-metapackage autoloading, path repositories, stub providers and a published artifact each prove a different boundary.
- Metapackages have no production source but still need closure and composition evidence.

## Exports and bytes

- Compare the exported files with the source: export-ignore rules, required runtime files present, dev-only and secret-bearing files absent.
- For static assets, check exact bytes, MIME types, application override precedence, vendor fallback, index fallback and any runtime HTML rewriting.

## Committed build output

For a committed distribution (such as the Admin SPA build): check freshness early to expose drift, let source settle, rebuild with the canonical tool, commit the rebuilt artifact, then run split-package and distribution acceptance from a clean exact head. Rebuild only when a build-determining source, dependency, configuration or distribution input changed, or the freshness check proves drift. Test-only or PHP-harness changes don't need a rebuild.

## Hosts

Record supported native hosts and which checks each can run. Symlink and POSIX-only controls belong to hosted Linux CI when the local host can't run them.
