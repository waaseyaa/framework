# Site verification adapters

`.ci/site-verify.php` is the single implementation of the provider-neutral
verification boundary. Everything else is an adapter onto it:

| Adapter | Invocation | Notes |
|---|---|---|
| Composer | `composer site-verify` | Portable. Runs through Composer's own PHP, so it works on Linux, macOS, and native Windows. **Use this one.** |
| Shell | `.ci/site-verify` | POSIX only. Convenience for shells and runners that expect an executable. |
| Direct | `php .ci/site-verify.php` | Equivalent to the Composer script; useful when Composer is not on the path. |

Run it locally or from any hosted runner after the exact Composer dependencies
have been installed.

## Exit codes

| Code | Meaning | Remedy |
|---|---|---|
| 0 | Verified. | — |
| 2 | Dependencies are not installed. | `composer install` |
| 3 | The project has no site contract yet. | `waaseyaa site:init`, then `waaseyaa install:init` |
| other | Whatever the generated verification command returned. | Read its output. |

Exit 3 is a definite state, not a failure of the tooling: `site:init` generates
`bin/maintenance/site-verify`, so verification before initialization has nothing
to verify. The entry point reports that without loading an autoloader or booting
the kernel, so it answers correctly even before `composer install` has run.

## Provider neutrality

The included GitHub Actions workflow is an adapter only. Forgejo, Gitea,
GitLab, Jenkins, Buildkite, or a local runner conform by installing the locked
dependencies and invoking the same command. No forge API, credential, event
shape, or repository URL is part of the site contract.
