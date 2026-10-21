# Generated verification portability (#2644)

## Who is affected

Any project with generated site artifacts that upgrades across a verification
contract change, including the executable-mode correction after alpha.306.
If you have never run `site:init`, there are no existing artifacts to regenerate.

## What changed

The earlier portability correction replaced `is_executable()` with proof that
`bin/maintenance/site-verify` is runnable PHP. Windows resolves executability
through PATHEXT, so the old assertion failed for this extensionless PHP file.

The generator after alpha.306 also declares the verifier as a regular `0644`
file, matching the `100644` mode Git records for a new file on Windows. Generated
acceptance tests require that exact mode on POSIX and still execute the script
through `PHP_BINARY`. Generated guidance, acceptance-test bytes and ownership
metadata change together. Use `composer site-verify` or
`php bin/maintenance/site-verify`; the reference-consumer harness uses PHP too.

**The generated artifact *set* is unchanged.** No file was added or removed.
That distinction matters: `SiteInitializationService` compares the artifact set
against the recorded ownership rows unconditionally, so a changed set would
refuse regeneration permanently, whereas changed bytes are recoverable by the
procedure below.

## What you will see

The first `site:doctor --strict` after the upgrade reports
`SITE010_GENERATED_ARTIFACT_DRIFT` against `.waaseyaa/generated.json`. Because
`bin/maintenance/site-verify` runs the doctor first, `composer site-verify` and
`.ci/site-verify` fail with it too.

In practice this rides along with a report you were already going to get: a
framework upgrade changes `composer.lock`, and the manifest still records the
previous lock digest, so `SITE011_COMPOSER_LOCK_DRIFT` fires on the same run.
The remedy is the same for both.

## What to do

Rebind the manifest to the dependency lock you just reviewed, then regenerate:

1. Set `framework.observed_lock_sha256` in `.waaseyaa/site.yaml` to the sha256
   of your current `composer.lock` (`sha256sum composer.lock`, or
   `php -r 'echo hash_file("sha256", "composer.lock");'`).
2. Run `php vendor/bin/waaseyaa site:init`.
3. Run `composer site-verify`.
4. Commit the regenerated artifacts.

This is the ordinary framework-upgrade procedure, not a special one. Rebinding
changes the manifest digest, which is the signal that the regeneration is a
reviewed upgrade rather than a substitution — so `site:init` regenerates the
changed file cleanly instead of refusing it.

If you regenerate **without** rebinding — the manifest digest is unchanged —
`site:init` refuses with `Generated artifact bytes changed without a
generator-version migration`. That message now names the rebind. There is no
generator-version migration engine, and none is being added; the rebind is the
supported path.

## Windows note

The published alpha.306 generator still declares `0755`. Before committing an
alpha.306 app on Windows, run `git add bin/maintenance/site-verify` followed by
`git update-index --chmod=+x bin/maintenance/site-verify`. Confirm
`git ls-files --stage bin/maintenance/site-verify` begins with `100755`. Otherwise
Linux strict doctor rejects the checkout even when Composer invokes it through
PHP. A local Windows `chmod` does not set Git's index mode.

After upgrading to a framework version containing the `0644` correction, follow
the reviewed lock-rebind and regeneration steps above. If the old file was
already indexed as executable, stage the regenerated file and run
`git update-index --chmod=-x bin/maintenance/site-verify` so Git records `100644`.
Do not manually edit ownership metadata or add execute bits to the new artifact.

Relatedly, if your repository applies a CRLF filter to `composer.lock`, the
digest recorded by the machine that ran `site:init` will not match the digest
computed by a machine that checked it out with different line endings. Keep
`composer.lock` out of any `text`/`eol` conversion in `.gitattributes`.
