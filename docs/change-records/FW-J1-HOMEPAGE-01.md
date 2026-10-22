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

### Sealed candidate and installed result

Candidate `4e809e69b041e82bb240973cae19b362fd293971` passed the repaired journey.
Its Git archive SHA-256 is
`1c05ae34ff38da3c974159e9f3904798605b694023b25eb1c12cc525b9ebefa2`.
The skeleton was separately exported at
`db29e321305110480429a2bdc5b124ae04d94f72`, the original baseline's revision.
The disposable skeleton manifest adds only mirrored path repositories for the
single export, retaining its requirements, autoload and explicit plugin policy.
Version `0.1.0-alpha.307` is solver metadata for that unpublished export.

- Fresh `composer create-project`, scripts/plugins enabled: 145 packages;
  all 67 Waaseyaa packages resolve from the same exported candidate. Installed
  manifests and the changed recipe bytes match the export; the loaded recipe
  class resolves inside the new application's own `vendor/`. Third-party
  versions are identical to the original published baseline.
- Candidate lock SHA-256:
  `4d41b0a2672e13ad3b2f0cb7b849863d2bf7851b3862f5025b2cc6f25063d012`.
- The original minimal seed, `site:init` preview/publication and `install:init`
  passed. `composer site-verify` passed strict doctor with zero findings and
  6 generated tests / 18 assertions, including the new homepage reservation.
- Real FrankenPHP `GET /` passed cold, warm and after replacing the server
  process. Each response was 200 and evaluated the application-owned marker
  `FW-J1-HOMEPAGE-01 {{ 6 * 7 }}` to `FW-J1-HOMEPAGE-01 42`. The marker is in
  the application's homepage, not a fallback or a generated index.
- `GET /page` returned 200 with the original 82-character `page_index` body.
  Its SHA-256 remained
  `ad54bae11aff0291bfd7b103ad77c1a08f03bab1c26d0d0f887d1da3c08130e8`.
- A separate fresh `composer install --no-dev` from the same lock installed
  exactly its 114 production packages. Normal post-create setup, generation
  and installation passed. Both `/` and `/page` passed the real HTTP checks.
  Correctly named PHPUnit and local-operator classes load in the dev positive
  control and are absent from the no-dev autoloader.
- Exact-candidate default preflight: 43 executed, 0 failures, 0 hosted-required,
  3 not-applicable, 135.8 seconds. The report binds the clean candidate above.
  PHP was 8.5.5; the ordinary preflight Composer capability is the machine's
  2.9.5. Fresh application installation/verification used the separately
  verified Composer 2.10.3. The serving binary reports PHP 8.5.7.

Self-review checked root ownership, preserved detail/prefixed routes, collision
refusal, recipe identity, mirrored source provenance and unchanged authorization
and persistence mechanisms. No independent review is claimed or used as an
integration approval. The code candidate is not merged, pushed or released.

The local evidence directory is `3199-evidence/20261021-repair` outside the
repository; it retains logs, exact locks, HTTP bodies, provenance records and
both source archives. All 55 indexed original-baseline files remain unchanged.
Every test listener was stopped after validating its exact application root;
the pre-existing listener at port 8080 was preserved. Caddy storage was isolated
under the new scratch root for these runs.

This qualifies the bounded candidate journey on native Windows development.
It does not close #3199: Linux S1/PHP-FPM, the remaining J1 failure controls,
full lifecycle replay and published adoption remain outstanding. The existing
symlink-based integration test still needs a capable host. Dev-only Bimaaji
route-metadata warnings remain a separate, unrepaired intake.
