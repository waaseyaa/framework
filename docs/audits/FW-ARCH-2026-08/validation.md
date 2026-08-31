# A0 evidence record

## Inputs

- Source census: clean `50750231a8036ae7afc68416fed8ea271e47159f` checkout.
- Dependency lock: SHA256 `f660a1af9c1340c2bc99d76810db7b14ef8ed06f11a7263801a3c9f8ab333f80`.
- Root manifest: SHA256 `df801946cfb0aa58ac63fe7bcfb077407f8108a60dfa88b29d3755700a748a1e`.
- Public map: SHA256 `fca0de79af2c6e3a89504921f83889e3f2c181465db7299535f49ae047c85cd0`.
- Native inventory runner: Windows, Node 24.13.1, PHP 8.5.5.
- Isolated PSR-4 probe: Linux PHP 8.5.9, Composer resolver from the lock-installed
  documentation worktree; the source and public-map inputs remain the frozen census.

## Results and limits

The initial census and its native rerun produce the same denominators. The portable
generator rerun passes the eight inventory consistency checks: unique file/package
allocation, package-kind counts, source counts, declaration resolution, provider
resolution, internal dependency resolution and complete executable layer assignment.

These checks do not certify runtime wiring, dependency installation, authorization,
or architecture. The separately executed autoload probe produces the four rows in
[qualification.md](qualification.md); it is a resolver experiment, not class-load
or production-install proof. No kernel was booted by the census.

An earlier WSL census against the mounted Windows checkout failed the mandatory
clean-tree guard; it is not counted as a successful Linux census. The native rerun
passed. Cross-host census byte parity is not asserted.

The original C-series `AUDIT.md` and named March audit report were not found by the
reachable-history and GitHub exact-path checks. Original findings remain requested;
surviving issues support the partial reconciliation but not a complete roster claim.

## Candidate verification

Publication evidence is recorded against exact candidate commits in the PR. Each
suite/gate must retain its own command exit and full log; neither the final command
in a loop nor `tail` establishes earlier success. Candidate checks are separate
from the frozen behavioral baseline and must not retroactively change its status.

The documentation candidate contains no changes to production packages, root
manifests/lock, public-surface classifications, test baselines, rosters or thresholds.
Independent review remains required. This record is not a release approval.
