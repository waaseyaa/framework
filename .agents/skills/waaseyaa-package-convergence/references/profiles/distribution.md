# Distribution checks

Apply to every package's split form, and in depth to metapackages, packages
with committed build output, and anything consumers install without dev
dependencies.

## Profiles and evidence classes

First decide which installation profiles the package supports, from its
charter and recorded decisions: primitive-only use, standalone split package,
kernel composition, curated metapackage (`core`, `cms`, `full`), the
`waaseyaa/framework` closure, generated application. An undecided profile is a
decision to record, not a failed check.

Record the evidence for each supported profile in exactly one class. Each
class proves a different boundary; never count one as another:

| Class | What it proves | Typical source |
| --- | --- | --- |
| source | behavior with monorepo path repositories and root autoload | the package suite, focused probes |
| closure artifact | exported bytes, composition and a `--no-dev` boot of the whole `waaseyaa/framework` closure built from sealed archives | hosted `ci/split-artifact-acceptance` on the exact base |
| metapackage | a curated metapackage installs and boots | `ci/core-only-boot`, `packaged-form` |
| installed consumer | the package's installed bytes inside a real application at a named lock | a consumer checkout's `vendor/`, read-only |
| standalone split | the package installed on its own and booted with `--no-dev` | an isolated install, such as `bin/test-isolated-package` |
| generated application | a freshly generated app installs, boots and runs the package | the skeleton path |
| native host | the same on a named native host | hosted Linux and Windows jobs |

Reused evidence names its run and job IDs, its base and what it asserts. A
closure boot that reflection-loads the package's classes shows they don't
extend dev-only symbols; it doesn't prove the package's behavior, and it
doesn't qualify the standalone split.

A supported profile with no evidence is a **qualification gap**: record it
with an owner (an existing CI job to extend, a proposed slice, or an accepted
residual with a rationale). A dispositioned gap doesn't keep the audit open.
It becomes a finding only when the package or its docs claim the profile
works, or when a consumer depends on it.

## Installed form

- Install the split package on its own and boot it with `--no-dev` when the standalone profile is supported. Nothing under `src/` may extend a dev-only class.
- Check optional dependencies both absent and present. Absence must not change unrelated behavior.
- Metapackages have no production source but still need closure and composition evidence.

## Exports and bytes

- Compare the exported files with the source: export-ignore rules, required runtime files present, dev-only and secret-bearing files absent.
- For static assets, check exact bytes, MIME types, application override precedence, vendor fallback, index fallback and any runtime HTML rewriting.

## Committed build output

For a committed distribution (such as the Admin SPA build): check freshness early to expose drift, let source settle, rebuild with the canonical tool, commit the rebuilt artifact, then run split-package and distribution acceptance from a clean exact head. Rebuild only when a build-determining source, dependency, configuration or distribution input changed, or the freshness check proves drift. Test-only or PHP-harness changes don't need a rebuild.

## Hosts

Record supported native hosts and which checks each can run. Symlink and POSIX-only controls belong to hosted Linux CI when the local host can't run them.
