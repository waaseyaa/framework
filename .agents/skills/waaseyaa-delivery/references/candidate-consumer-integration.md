# Candidate consumer integration

Use for authorized development spanning Framework and an application before a
new package release is appropriate. Keep application policy and release timing
in the application repository. Shared records use synthetic, consumer-neutral
scenarios. This procedure grants no release or deployment authority.

## Two qualification states

- **Candidate-qualified:** an exact Framework candidate passes the affected
  journey in an exact application candidate using installed package bytes.
  Development may continue on that tested pair. Record pending published
  adoption separately; do not describe the application as release-ready.
- **Release-qualified:** compatible published packages are installed from the
  application's intended release lock, with provenance and affected journeys
  verified again, including production no-dev composition. The application's
  remaining release gates and deployment authorization still apply.

An upstream issue can close when its own acceptance is met. That does not close
the consumer's adoption gate. A monorepo test alone establishes neither state.

## Reproduce an installed candidate

1. Record full Framework and application commit SHAs and the baseline published
   lock. Commit scoped work before qualification; a moving checkout or branch
   name is not a candidate identity. Export each exact commit into task-owned
   disposable directories with `git archive`. Record archive SHA-256 digests.
2. In the disposable application only, add Composer path repositories pointing
   to the exported Framework packages, with `options.symlink: false`. Preserve
   the application's requirements, autoload rules and explicit plugin policy.
   Never borrow vendor, add Framework root autoload mappings, edit installed
   code, ignore platform requirements or enable all plugins to obtain a pass.
3. Make every installed `waaseyaa/*` package in the affected dependency closure
   resolve from the same export. Inspect the solved lock, including transitive
   packages, rather than assuming sibling `repositories` declarations apply to
   a root consumer. Fail on unexplained published/candidate mixing. If the
   application requires the monorepo distribution or its replacements, resolve
   that composition explicitly before claiming split-package proof.
4. Archives lack Git version inference. Where needed, set path-repository
   `options.versions` for each package to the compatible cohort label from the
   candidate's `VERSION` and package constraints. Record every override. That
   label is solver metadata, never proof of a published release; the SHA and
   archive digest identify the candidate. If constraints cannot resolve, record
   the conflict and repair its owner rather than silently widening requirements.
5. Save the overlay manifest and generated lock, install into a fresh local
   vendor directory, and record PHP/Composer versions and exact commands.
   Keep application production manifests and locks unchanged. Reproduction needs
   the exports, repository mapping and lock, not a lock pointing to an unavailable
   developer directory. Preserve a portable recipe with relative paths.
6. Verify representative changed classes resolve into that install, and compare
   installed package files with the export. Exercise the real application boot,
   affected success journey and relevant refusal/failure cases using synthetic
   data. Repeat relevant boot/discovery checks in a separate fresh `--no-dev`
   install from the candidate lock. Package autoload success alone is inadequate.

The existing `tests/PackagedForm/check-cli-io-consumer-contract` illustrates exact
archive export and mirrored installation for a synthetic consumer. Its wildcard
requirements and plugin settings are fixture-specific, not application defaults.
This recipe is development integration evidence, not proof of split-release
archive contents or production readiness. Do not bypass an application's
published-lock verifier; record its inapplicability to the isolated overlay and
provide the candidate provenance checks above. Its normal release checks remain.

## Evidence and handoff

Use the existing application change record: both SHAs, baseline and candidate
lock hashes, archive hashes, package/version/source map, overlay recipe, commands,
results, reviewer verdict and residual release gates. No separate dashboard or
installer is required. Requalify when either candidate or relevant environment
changes; unchanged evidence can be reused under the repository's normal policy.

When a release is authorized, use the existing Framework release workflow. Then
remove candidate overlays, adopt the compatible published cohort in the real
application lock and rerun applicable application checks and formerly blocked
journeys. Verify installed provenance and no-dev composition. Report candidate
qualification, Framework publication, consumer adoption and deployment as
separate events; none implies the next.
