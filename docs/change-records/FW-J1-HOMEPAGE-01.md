# FW-J1-HOMEPAGE-01: preserve the skeleton homepage after generation

Owning journey: #3199 / FW-PACKAGE-CONVERGENCE-01. Base:
`5fe8b8aebe585d2a6dac2adcf839c98ee4ac8b5e` (alpha.307).

## Problem and accepted behavior

The published fresh-install baseline on 21 October 2026 completed creation,
minimal generation, installation and verification. Its first `GET /` returned
200 with an empty `page_index` listing. The default seed's `/{slug}` detail
route caused the published-content recipe to generate an index at `/`, hiding
the application homepage promised by #2651 and J1-C.

The maintainer requested the repair, regression coverage and a fresh baseline.
Keep `/` owned by the skeleton SSR homepage. For root-level content, derive
the collection from its bundle ID (`page` becomes `/page`) and preserve the
authored `/{slug}` detail route. Prefixed collections retain their current
parent path. Existing collision refusal also covers the derived bundle path.
The site-golden-path contract records the literal collection URL reservation.

## Scope and verification plan

Own the published-content recipe, its unit/generated acceptance tests, the
existing skeleton homepage integration test, the reference-consumer recipe
digest, this record, the owning spec and release fragment. Use the existing
Symfony routing and Twig composition. This is generated URL policy, not a
new router, authorization policy, schema or migration mechanism.

First capture failing recipe and composed-homepage regressions. Repair the
producer, then run the focused recipe, preset and homepage checks plus governed
preflight. Review the bounded delta through recorded self-review; retain any
host or integration-review limits explicitly. No publication or integration
is authorized by this implementation request.

For installed proof, seal the exact candidate and follow the delivery skill's
candidate-consumer integration procedure: one exported Framework cohort,
mirrored Composer installation in fresh scratch applications, exact lock and
file identity checks, ordinary generation/install/verification, real cold and
warm HTTP and process restart, plus a fresh no-dev install. Keep the original
published failure unchanged. Candidate proof is not published-release or Linux
S1 qualification. Do not overwrite installed source or reuse the failed app.

## Upgrade boundary

Existing applications retain their generated URLs until explicit reviewed
regeneration. The recipe digest changes with the collection policy, so an old
authored recipe selection cannot silently claim the new output. Do not edit
generated metadata or migrate real application URLs during this repair.

## Evidence

The recipe regression failed before repair: the root collection was `/`, and
the derived `/page` collision was not refused. After repair, focused recipe,
manifest assembly and initialization-handler checks passed 48 tests / 261
assertions on PHP 8.5.5, Composer 2.10.3 and native Windows 11.

The unchanged shared fixture cannot create symlinks on this PC, so the
composed-homepage integration suite is pending a capable host. An additional
initialization-service suite exposed Windows directory-sync, path-separator,
hard-link and symlink assumptions in unchanged code; it is not a passing run.
Real installed HTTP verification is the local composed-boundary witness.

The original baseline is retained locally under
`3199-evidence/20261021-633a96fd` outside the repository. Candidate installation,
HTTP evidence and final preflight are pending at this checkpoint. The first
preflight exposed fragment formatting and the CLI spec coupling; those were
corrected before sealing the candidate. No merge or publication is implied.
