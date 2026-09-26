# Distribution checks

Apply to every package's split form, and in depth to metapackages, packages
with committed build output, and anything consumers install without dev
dependencies.

## Installation profiles

Decide which of these the package supports, from its charter and recorded
decisions. An undecided profile is a decision to record, not a failed check.

- **primitive-only:** a consumer uses the package's classes directly, without
  the kernel;
- **standalone split:** the package installed on its own and booted, including
  with `--no-dev`;
- **kernel composition:** the package's providers and policies discovered and
  booted by the kernel;
- **metapackage:** installed through `core`, `cms` or `full`;
- **framework closure:** installed through `waaseyaa/framework`;
- **generated application:** a freshly generated application.

## Evidence classes

Each piece of evidence belongs to exactly one class; never count one class as
another. A profile can rest on several pieces of evidence. The host a run used
(native Windows, hosted Linux) is recorded on the run, not as a class.

| Class | What it proves | Can qualify | Typical source |
| --- | --- | --- | --- |
| source | behavior with monorepo path repositories and root autoload | nothing; source evidence is "reproduced", never "qualified" | the package suite, focused probes |
| closure artifact | exported bytes, composition, and a `--no-dev` install and boot of the whole `waaseyaa/framework` closure built from sealed archives | framework closure; kernel composition for "boots with the package present" | hosted `ci/split-artifact-acceptance` on the exact base |
| metapackage | a curated metapackage installs and boots | metapackage | `ci/core-only-boot` and `packaged-form` cover `core` only; `cms` and `full` need their own evidence |
| installed consumer | the package's installed bytes inside a real application at a named lock | the profile that application uses | a consumer checkout's `vendor/`, read-only |
| standalone split | the package installed alone and booted with `--no-dev` | standalone split, primitive-only | an isolated scratch install; `bin/test-isolated-package` covers only `access` and runs a dev install, so it isn't this class for other packages |
| generated application | a freshly generated app installs, boots and runs the package | generated application | the skeleton path, or a hosted job that creates the project |

Reused evidence names its run and job IDs, its base, and the surfaces it
asserts, and confirms that the job's closure actually contains the package.
Hosted logs expire: transcribe the asserted surfaces into the ledger's
evidence runs rather than capturing the log under `evidence/`, whose header
accepts only commit and issue sources. A closure boot that reflection-loads
the package's classes shows they don't extend dev-only symbols; it doesn't
prove the package's behavior, and it doesn't qualify the standalone split.

## Qualification gaps

A supported profile with no qualifying evidence is a **qualification gap**.
Record it with an owner: an existing CI job to extend, a proposed slice, or
an accepted residual with a rationale. Once recorded that way it doesn't keep
the audit open. It becomes a finding only when the package or its docs claim
the profile works, or when a consumer depends on it.

## Installed form

When you run these checks, record their evidence; otherwise record the gap.

- Install the split package on its own and boot it with `--no-dev`. Nothing under `src/` may extend a dev-only class.
- Check optional dependencies both absent and present. Absence must not change unrelated behavior.
- Metapackages have no production source but still need closure and composition evidence.

## Exports and bytes

- Compare the exported files with the source: export-ignore rules, required runtime files present, dev-only and secret-bearing files absent.
- For static assets, check exact bytes, MIME types, application override precedence, vendor fallback, index fallback and any runtime HTML rewriting.

## Committed build output

For a committed distribution (such as the Admin SPA build): check freshness early to expose drift, let source settle, rebuild with the canonical tool, commit the rebuilt artifact, then run split-package and distribution acceptance from a clean exact head. Rebuild only when a build-determining source, dependency, configuration or distribution input changed, or the freshness check proves drift. Test-only or PHP-harness changes don't need a rebuild.

## Hosts

Record supported native hosts and which checks each can run. Symlink and POSIX-only controls belong to hosted Linux CI when the local host can't run them.
