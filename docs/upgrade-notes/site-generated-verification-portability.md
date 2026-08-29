# Generated verification portability (#2644)

## Who is affected

Any project that ran `waaseyaa site:init` on alpha.299 or earlier and then
upgrades the framework. If you have never run `site:init`, nothing here applies.

## What changed

The bytes of one generated file changed: `tests/Acceptance/SiteGoldenPathTest.php`.
It asserted `is_executable()` on `bin/maintenance/site-verify`, which is an
extensionless file. Windows resolves executability through PATHEXT, so that
assertion fails there for a perfectly good file. The test now asserts the
portable property on every host — that the command is runnable PHP — and keeps
the execute-bit assertion on POSIX only.

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

A project initialized on Windows cannot carry a POSIX execute bit, so
`bin/maintenance/site-verify` will not be directly executable if you later
deploy that checkout to Linux. `composer site-verify` is unaffected — it
re-executes the command through `PHP_BINARY` — which is why it is the documented
invocation. Set the bit in your deployment if you invoke the file directly.

Relatedly, if your repository applies a CRLF filter to `composer.lock`, the
digest recorded by the machine that ran `site:init` will not match the digest
computed by a machine that checked it out with different line endings. Keep
`composer.lock` out of any `text`/`eol` conversion in `.gitattributes`.
