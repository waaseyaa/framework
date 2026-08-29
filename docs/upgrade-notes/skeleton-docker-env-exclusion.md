# Skeleton `.env` exclusion from Docker build artifacts

Applies to any application created from the Waaseyaa skeleton before this
change that builds a container image from the shipped `Dockerfile`.

## What was wrong

`bin/post-create-setup.php` writes `.env` when `composer create-project` runs.
That file carries two values generated on the spot:

- `WAASEYAA_JWT_SECRET` — hex of 32 random bytes;
- `WAASEYAA_APP_SECRET` — `base64:` plus 32 random bytes.

`skeleton/.dockerignore` did not exclude `.env`, and the `production` stage of
`skeleton/Dockerfile` runs `COPY . /app`. The generated `.env` therefore entered
the build context, the final image filesystem, and a saved image layer. Anyone
who could pull, `docker save`, or otherwise read a layer of a published image
could read both secrets, including from an older tag after the file was removed
from a later one — deleting a file in a subsequent layer does not remove it from
the layer that added it.

This is disclosure of secret material inside a distributable artifact. It is not
an authentication bypass, and the other values in that `.env` do not take effect
in the image: `EnvLoader::load()` mirrors process environment into `$_ENV` before
Symfony Dotenv runs, so the `Dockerfile`'s `ENV APP_ENV=production` and
`ENV APP_DEBUG=false` win over the file's `APP_ENV=local` / `APP_DEBUG=true`, and
`HttpKernel::shouldUseDevFallbackAccount()` is separately gated.

## What changed

`skeleton/.dockerignore` now excludes `.env`, `.env.*`, `**/.env` and
`**/.env.*`, and re-admits the placeholder template with `!.env.example` and
`!**/.env.example`. The negations come last on purpose: in `.dockerignore` the
last matching pattern wins, and `.env.*` matches `.env.example`.

New projects created after this change need no action.

## Upgrade an existing project

1. Apply the same exclusion to your project's `.dockerignore`. Append these
   lines, keeping the negations last:

   ```
   .env
   .env.*
   **/.env
   **/.env.*

   !.env.example
   !**/.env.example
   ```

2. Confirm the build context no longer carries the file. This prints the exact
   inventory the daemon receives, after `.dockerignore` filtering:

   ```sh
   printf 'FROM scratch\nCOPY . /context\n' > /tmp/context-probe.Dockerfile
   docker build -f /tmp/context-probe.Dockerfile -t context-probe .
   docker create --name context-probe-export context-probe /noop
   docker export context-probe-export | tar -t | grep -E '(^|/)\.env'
   docker rm -f context-probe-export && docker image rm -f context-probe
   ```

   The only match must be `context/.env.example`. Any other `.env*` line means
   the pattern did not apply — check for a `.dockerignore` in the directory you
   actually pass as the build context, which is the only one Docker reads.

3. Check images you have already built or published. A leaked secret is not
   removed by rebuilding; treat every existing tag as exposed until checked:

   ```sh
   docker save your-image:tag -o /tmp/image.tar
   mkdir -p /tmp/image && tar -xf /tmp/image.tar -C /tmp/image
   grep -ra 'WAASEYAA_JWT_SECRET' /tmp/image | head
   ```

   Search every tag you still publish, not just the newest one.

4. If any built or published image contains the file, rotate both values. They
   are independent:

   - `WAASEYAA_JWT_SECRET` signs authentication tokens. Rotating it invalidates
     every outstanding token, so expect all sessions to end.
   - `WAASEYAA_APP_SECRET` is the kernel master secret. Read
     [`docs/upgrade-notes/application-secret-hkdf.md`](application-secret-hkdf.md)
     and, if you run the OIDC issuer,
     [`docs/upgrade-notes/oidc-secrets-at-rest.md`](oidc-secrets-at-rest.md)
     before changing it: persisted envelopes and keyed lookup values are derived
     from it and do not survive a change without the documented migration.

   Rotate in your deployment's secret store and inject both as environment
   variables. The image should never carry either value.

5. Delete or make private any published image tag that contains the file. A
   registry keeps the old layer even after you push a new tag.

## Regression proof

`bin/check-skeleton-docker-secret-exclusion` builds an image from a real
create-project context and asserts the generated secrets are absent from the
build-context inventory, the image filesystem, and every saved layer. It runs a
positive control first — the same inspections against a context with
`.dockerignore` removed, which must observe the leak — so a passing run cannot
mean the harness stopped looking. `ci/skeleton-create-project` runs it on every
push, and `tests/Architecture/SkeletonDockerSecretExclusionTest.php` runs it
locally whenever a Docker daemon is reachable.

The gate is cross-platform. `php bin/check-skeleton-docker-secret-exclusion
--self-test` checks the subprocess runner alone -- no Docker, no daemon -- and
is the quickest way to confirm the tooling works on your host before you
investigate a Docker result.
