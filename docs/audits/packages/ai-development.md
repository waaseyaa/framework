# `waaseyaa/ai-development` convergence audit

November 1 reconciliation: this report and its ledger retain the October 11
assessment identity and limits. At main `c4aff57585`, package source is unchanged
except first-party manifest floors moving from alpha.305 to alpha.308. This is
not fresh installed qualification. The new CMS-free default AI closure requires
delta assessment under [FW-BASIC-APP-STARTER-01](../../change-records/FW-BASIC-APP-STARTER-01.md).

- **Milestone:** assessed. Repair ready: yes, with no package-owned repair. Converged: not claimed.
- **Audit state:** assessed. **Remediation state:** no action required.
- **Base:** `bff4b104d29ba484307dc6655796be10837ff85e`, audited `2026-10-11`.
- **Dependency identity:** `composer.lock` SHA-256 `8719a36fb5792012ce6f08db9df52351d9339345606b882e9d07ed2bfe3461bc`; PHP `8.5.5`; native Windows. Published-consumer lock SHA-256 `6c4cb9d48444651dcb704fd1f06326de2d6e3b947beea562d99e71757c0ac27a` is separate evidence.
- **Evidence freshness:** both production files are current at the base and byte-identical to the `v0.1.0-alpha.305` split tag. The installed consumer used that published split package and a separate skeleton `dev-main` checkout, so it does not qualify the complete candidate-local #2653 journey.
- **Owner:** `waaseyaa/framework#3118` for package coverage; delivery integration remains under #2653.
- **Profiles applied:** package identity, development dependency closure, structural quality and distribution. **Not applied:** domain execution, persistence, HTTP input and generation/build: this metapackage owns no runtime code, route, write, generated file or adapter.
- **Structured ledger:** `docs/audits/packages/ai-development.ledger.json`: 0 findings, 0 refuted leads, 20 checklist answers, 0 handoffs, 0 decisions (0 open), 0 uncertainties (0 open), 0 probe entries, 7 evidence runs.

## Summary

The package is a two-file, opt-in development metapackage. Its manifest requires `waaseyaa/ai-agent` and `waaseyaa/testing` at the current alpha floor, and has no source, resource, provider or public PHP symbol. The root and skeleton put it under `require-dev`; CP009 guards the production closure of Framework, core, cms and full, including the `ai-agent` principal's home package. The package's transitive closure excludes `waaseyaa/mcp`; the real provider sweep and a seeded MCP-route control pass.

The published alpha.305 package files match this base byte for byte. A disposable published skeleton installed the dev graph, ran project initialization and a real stdio handshake, then removed `ai-development`, `ai-agent` and `testing` with `composer install --no-dev`. Bimaaji and CLI remained through the Framework runtime graph, as expected. The whole client-configuration, documentation-corpus, update and uninstall journey is not claimed here; #2653 and #2665 own that qualification. There is no package-owned finding or repair in this two-file assessment.

## Charter

- **Owns:** one Composer selection for the local development dependency plane, its two direct members and the package-level install guidance.
- **Does not own:** tools, principal, stdio server, HTTP routes, client descriptors, documentation corpus, generated guidance, installation orchestration or production runtime behavior.
- **Consumers:** the skeleton's `require-dev` graph and applications that explicitly `composer require --dev waaseyaa/ai-development`; the root aggregate also uses a dev requirement. The CLI transport is supplied by the application's Framework/CLI composition, not by this metapackage.
- **Dependencies:** direct `waaseyaa/ai-agent` and `waaseyaa/testing` at `^0.1.0-alpha.305`, with matching local path repositories for development. The manifest contains no Symfony runtime dependency or custom implementation. Transitive composition and refusal belong to the two dependency packages and CLI.
- **Public surface:** Composer package identity and its two-member dependency contract only. There are no exported PHP symbols, wire messages, extension seams, aliases or adapters. Composer's native dependency resolver is the only package-selection mechanism.
- **Installation profiles:** opt-in development metapackage inside a supported Waaseyaa application, including a generated skeleton. Primitive-only and standalone boot are not package claims. Production closure inclusion is explicitly forbidden.
- **Evidence it works:** candidate source policy and route controls, split-tag byte comparison, and a published alpha.305 generated consumer's dev/no-dev resolution. The consumer's complete #2653 journey remains a separate qualification obligation.

## Roster

| File | Role | Classification | Level | Findings |
| --- | --- | --- | --- | --- |
| `packages/ai-development/composer.json` | Composer identity, direct dev members, version floor and path repositories | owned and coherent | reviewed | none |
| `packages/ai-development/README.md` | Selection and containment guidance | owned and coherent | reviewed | none |

This is the complete tracked non-test package roster: 30 manifest lines and 45 README lines. There is no `src/`, public-surface declaration, build output or resource tree.

## Intake

No committed package ledger routes a finding or handoff to `waaseyaa/ai-development` at this base. The live issue search was reconciled with #2655, #2738, #2649, #2658, #2653 and #2665. No package-owned repair is opened by this audit. Historical ADR-022 implementation wording and broader #2653 acceptance are reconciled in the program record, not treated as evidence that the two-file package is defective.

## Package findings

None. The no-MCP closure and production exclusion are executable source contracts with negative controls. An absent client descriptor, unfinished corpus and missing upgrade/uninstall workflow are integration work owned by #2663, #2661/#2662 and #2664, respectively; this metapackage does not claim to implement them. The README's shorthand for three admitted read tools includes two graph reads and one specs-search tool; #2661/#2662 determine when that third tool returns a useful versioned corpus.

## Cross-package findings

None newly attributed to this package audit. #2653's delivery roadmap records the CLI non-interaction, generated `AGENTS.md` ownership and client-configuration boundaries against their actual owners. Those findings are not package-owned remediation here.

**Handed-off leads:** 0.

## Refuted leads

None recorded. A no-code manifest does not itself prove a safe installation. The closure, provider and installed-consumer controls below supply that proof within their stated scope.

## Qualification by profile

| Profile | Supported | Evidence class | Evidence | Gap and owner |
| --- | --- | --- | --- | --- |
| primitive-only | no | none | No classes or primitives export from a metapackage. | none |
| standalone split | no | none | A metapackage has no bootable implementation outside a host application. | none |
| kernel composition | yes, transitively in development | source | Real provider sweep covers the derived dependency closure and MCP positive control. | Installed candidate-wide provider qualification: #2665. |
| metapackage | yes, opt-in development | generated application | Published alpha.305 skeleton locked and installed the bundle in `packages-dev`; candidate package files equal split-tag bytes. | No package manifest gap; full #2653 journey: #2665. |
| framework closure | no production inclusion | source | CP009 green and seeded negative tests exist; the manifest is deliberately outside runtime closure. | None; hosted Linux owns POSIX seeded tests. |
| generated application | yes | generated application | Disposable published skeleton installed the bundle, initialized a site, ran stdio and removed dev-only members on no-dev install. | Candidate-wide client/update/upgrade qualification: #2665. |

The generated-consumer proof used skeleton `dev-main` at `b6e839972f1e6eb0e241d4492faeeb31ebfc8952` and split packages `v0.1.0-alpha.305`; it is not an exact candidate closure artifact. `waaseyaa/ai-development` itself was split ref `bf8338a4508eaaf15eccb237304260dc15720947`. GitHub split-tag bytes matched both candidate package files: manifest SHA-256 `f3a5f6d8ba77fd342f10ac35dab0ab502f9aafa0932494e715cbc2938b839816`, README SHA-256 `6ccf00a1217afeeb66be683a1e1deacaa15797c08a978bfcd98622e184f1d45a`.

## Profile checklists

- **Identity and public contract:** name, type, direct members, current version floor, split target and `require-dev` placement are explicit. No PHP symbols or wire contract exist in this package.
- **Structural quality:** separation of concern and single responsibility are satisfied by Composer selection plus a short README. The two files make human navigation direct. PHPDoc is inapplicable; comments and README were compared with the manifest and current three-tool roster. No unused state, duplicated algorithm, competing authority or adapter is present. The path repositories mirror the two declared dependencies under CP007; they are not a second dependency policy.
- **Failure and refusal:** CP009 rejects production promotion, and the source route test derives the full local closure and detects an injected MCP route. Those seeded subprocess controls are Linux-owned; native Windows cannot execute their POSIX harness unmodified.
- **Distribution:** published alpha.305 package bytes, generated-app lock and no-dev removal support this metapackage contract. Source tests alone are not counted as an installed consumer. The final candidate-wide journey is #2665's separate acceptance.
- **Symfony fit:** no generic infrastructure is implemented or retained here. Composer itself resolves packages; Symfony component replacement has no subject in this two-file package.

## Decisions and uncertainties

No package-level open decision or uncertainty. #2663's client configuration, #2664's generated-output ownership and #2661/#2662's corpus/search contracts remain program decisions or work, not a reason to widen this metapackage's charter.

## Remediation plan

No package-owned repair slice. `repair_ready` means the package assessment has no repair to queue; it does not mark the #2653 journey delivered or this package converged. Keep the package row under #3118 and the delivery slices under #2653.

## Not reviewed

- `ai-agent`, `testing`, Bimaaji, CLI and site-contract internals receive bounded boundary review in #2653 and retain their own package owners. This assessment classifies only `ai-development`'s two production files.
- No packaged coding-client configuration or project upgrade was qualified by this package audit. #2663, #2664 and #2665 own those controls.

## Host limits

- Native Windows executed 29 of 38 assertions-bearing focused architecture cases successfully; nine failures arose from POSIX launcher/executable assumptions (`ROOT_DIR` and Bash executable mode). `SplitTargetReachabilityGateTest` likewise reached `rm` and shell variable syntax. Hosted Linux owns the seeded subprocess gates and split-artifact acceptance.
- The native host did run `LocalPlaneNoMcpRouteTest` 7/149 and `MetapackageSmokeTest` 15/15 independently. No Windows result is presented as hosted Linux evidence.

## Evidence

| Command or probe | Base | Dependency identity | Runner | Proves | Result |
| --- | --- | --- | --- | --- | --- |
| `composer validate --strict packages/ai-development/composer.json` | `bff4b104` | lock `8719a36f` | native Windows | manifest shape | pass |
| `php bin/check-composer-policy` | `bff4b104` | lock `8719a36f` | native Windows | current closure and dependency policy | pass |
| `php bin/check-package-layers` | `bff4b104` | lock `8719a36f` | native Windows | metapackage/layer policy | pass, accepted unrelated cycle warnings |
| `php vendor/bin/phpunit tests/Architecture/LocalPlaneNoMcpRouteTest.php --no-coverage` | `bff4b104` | lock `8719a36f` | native Windows | derived closure, provider route refusal and positive control | 7 tests, 149 assertions pass |
| `php vendor/bin/phpunit tests/Integration/Phase23/MetapackageSmokeTest.php --no-coverage` | `bff4b104` | lock `8719a36f` | native Windows | metapackage declarations/autoload | 15 tests, 15 assertions pass |
| split-tag file-byte comparison via GitHub API | `bff4b104` and split alpha.305 | tag refs and file SHA-256 above | native Windows | published files equal this candidate's package files | both equal |
| published skeleton create-project, dev install, site init, stdio probe, no-dev install | skeleton `b6e83997`, split alpha.305 | consumer lock `6c4cb9d4` | native Windows | installed development bundle and dev-only removal | pass within stated scope |

The focused three-test-file architecture run returned 9 failures in 38 cases from native Windows shell/executable assumptions; it is not counted as a pass. The candidate-local preflight receipt was green on five executed, 36 reused, no failed or hosted-required gates, and five not-applicable gates; it is a separate program receipt.

## Scorecard

- **Elapsed and agents:** package lane elapsed and token usage unavailable; one package auditor plus the program's independent review lane.
- **Source and consumers:** 0 PHP source files, 0 PHP source lines, 2 production files / 75 text lines; generated skeleton and explicit development-bundle consumers.
- **Intake and profiles:** 0 intake items; identity, structure and distribution profiles applied; 6 installation profile dispositions.
- **Findings:** 0 package-owned, 0 cross-package; no verification reversals.
- **Record size:** 1 human record and 1 structured ledger; exact lines, words and bytes are reported by local file checks after writing.
- **Probes:** 0 written, 0 retained; existing seeded controls and installed consumer suffice for a zero-finding assessment.
- **Open:** 0 open decisions; 0 open uncertainties; 0 findings without a filed issue.
