# Alpha.309 publication and consumer qualification

Status: **tagged source qualified; publication incomplete; published consumer qualification blocked**.

The source at `76afb3f84491476e381d9d3f17df7bf71147a131` passed all five governed release-cut gates and was atomically tagged as `v0.1.0-alpha.309`. The unchanged tag, full CI, Release Readiness, unsharded seed/counts, package split provenance and checksummed SBOM are recorded in `qualification-state.json` and the producer evidence files. These files came from the successful evidence-assembly job, even though final publication failed.

The canonical publication verifier exhausted its deadline with `waaseyaa/publishing` and `waaseyaa/wayfinding` absent from Composer P2 metadata. The GitHub Release announcement was correctly withheld. The real registry-only consumer at harness `2df1d4160f0a93716fdc953a72bad63992f95c01` failed dependency resolution because CLI and API/workflows need publishing alpha.309. No application boot, journey or installed-byte verification ran; **zero of seven profiles are complete**. `consumer-resolution-refusal.txt` is failure evidence, not a passing feature result. No development alias, local package override or vendor patch was used. No consumer lock was produced because resolution failed.

The complete-cohort constraint repair has a real solver discriminator: the former historical fixture mixed alpha.308/309; the corrected fixture retained the same 41-member closure entirely at alpha.308. This is harness validation, not published-feature qualification. The availability snapshot is explicitly an earlier observation; the canonical verifier's final missing-package result owns publication status.

When both records become visible, rerun only the failed jobs of split/publication run `43616058061`, preserving its exact source and evidence. Then rerun the complete seven-profile consumer matrix with the recorded harness candidate and `--published-tag=v0.1.0-alpha.309`, using an external evidence directory and disk-backed TMPDIR. Retain fresh manifests/locks, package origins/bytes, normal/no-dev results in both layouts, both historical upgrade scenarios and the no-engagement control. The historical column fixture remains synthetic. No tag movement, new release, FETDER adoption, deployment or production change is implied by recovery or qualification.

Latest investigation: Packagist recorded both manual updates at 18:07 UTC, but
P2 remained stale through 18:19:17 UTC. The November 14 upstream replication
incident is a plausible explanation, not confirmed for these two packages.
See `packagist-incident-confirmation.json`, `packagist-post-update-changes.json`,
`packagist-post-update-http-capture.json` and `packagist-post-update-observations.jsonl`.
Recovery tooling landed at `e02682510` with passing main CI `43625277062`;
this does not change the blocked qualification status above.
