# Five-package convergence audit

Fresh assessment at Framework commit `3161864a5d1af57621e4819e9720f12e12ccdae7`, dated 2026-10-19. The assessment ran against that unchanged source base; this publication retains its evidence and qualification limits.

Change record: [FW-PACKAGE-CONVERGENCE-01](../../change-records/FW-PACKAGE-CONVERGENCE-01.md).

The run classified 916 shipped files across 108,083 PHP lines. Audit, Entity-storage, Foundation and User received attributed full source or metadata review. CLI has 210 fuller reviews and 82 explicitly sampled files. Source review, synthetic reproduction and installed qualification are kept separate.

| Package record | Files | Ordinary findings | Private findings |
| --- | ---: | --- | --- |
| [Audit](audit.md) | 72 | 1 medium, 2 info | 3 verified |
| [Entity-storage](entity-storage.md) | 151 | 1 info | 2 verified |
| [Foundation](foundation.md) | 369 | 8 low | 1 verified |
| [CLI](cli.md) | 292 | 2 low, 4 info | 1 verified |
| [User](user.md) | 32 | 1 low, 1 info | 1 pending |

There are 28 findings: 20 ordinary findings with completed independent review, seven privately verified findings, and one incomplete private lead. Private verification is bounded by the source and synthetic evidence recorded in custody; it does not establish current deployed exposure. None of the packages is claimed converged.

**Final status:** Audit, Entity-storage, Foundation and CLI are assessed. User remains in progress because required independent private verification is incomplete. The independent final critic passed the corrected records and redaction checks; both critic integrity comparisons passed. Assessment means the evidence and gaps are dispositioned, not that repairs have landed or installed profiles are qualified.

The new artifact-write finding is `CLI-IO-002`; the earlier `CLI-IO-001` in #3117 retains its machine-output meaning. This publication-only identifier correction does not change the finding or its verification.

## Main ordinary repairs

- Audit: provide the closed status-only batch contract under [#3184](https://github.com/waaseyaa/framework/issues/3184), preserving truthful per-entity evidence and explicitly settling caller-transaction behavior.
- Foundation: repair provider lifecycle and diagnostics, opt-in HTTP negotiation and conditional responses, event-label association, invalid logging-default handling, and schema-drift guidance. The records preserve default-path and valid-configuration controls.
- CLI: refuse fractional import limits before activity and report failed final artifact writes as failures. Six bounded ordinary slices include documentation, canonical config ownership, initialization cohesion and maintained process-component equivalence.
- User: make advertised session-interface behavior truthful while preserving current flat session storage obligations; update documentation and navigation.
- Entity-storage: correct six public descriptions and comments. Existing scaling, concurrency, recreation-workflow and legacy-data work remains with its owners.

## Related work

| Area | Existing destinations |
| --- | --- |
| Audit lifecycle and export | [#3010](https://github.com/waaseyaa/framework/issues/3010), [#3011](https://github.com/waaseyaa/framework/issues/3011), [#3015](https://github.com/waaseyaa/framework/issues/3015) |
| Storage contextual-query qualification | [#3006](https://github.com/waaseyaa/framework/issues/3006) |
| Foundation and failure behavior | [#3123](https://github.com/waaseyaa/framework/issues/3123), [#3203](https://github.com/waaseyaa/framework/issues/3203) |
| CLI and post-mutation outcomes | [#3117](https://github.com/waaseyaa/framework/issues/3117), [#3035](https://github.com/waaseyaa/framework/issues/3035) |
| Package and installed-profile program | [#3118](https://github.com/waaseyaa/framework/issues/3118), [#3199](https://github.com/waaseyaa/framework/issues/3199) |

The intake captured all 200 open issues, 15 open PRs and 343 Project items. Relevant closed search was capped at 100. Comments were refreshed for 55 selected issues, including 22 with discussion. All 244 document candidates have inventory dispositions; 17 named specification paths have current traces. Historical runs and issue comments are attributed as historical evidence.

## Verification and limits

- User: 402 tests and 823 assertions passed.
- Entity-storage: focused 31 tests and 115 assertions passed.
- Foundation: focused 240 tests and 437 assertions passed, plus independent contract/HTTP/operator probes. The operator batch passed 39 checks.
- Audit: 369 tests and 1,024 assertions ran with one native-Windows refusal-fixture failure. The fixture assumes /proc is unwritable; here it appended one checkpoint to a pre-existing test artifact. Prior data was preserved, and the fixture needs hosted Linux confirmation.
- CLI: focused 29 tests and 113 assertions had two native-Windows permission/symlink failures. Independent terminal command controls passed across 33 invocations.
- Scoped PHPStan reported zero errors; the existing package layer gate passed. Overlapping suites and probes are not added into a unique coverage total.
- All five structured ledgers are validated against their records and coverage index. The final independent finish-criteria, citation and redaction review passed after one bounded correction round.

No fresh no-dev installation, generated application, current downstream deployment or production database was qualified. Missing profile evidence has named owners. The private User lead was interrupted before its required independent verdict and has not been retried or reconstructed; User cannot be marked assessed.

Private briefs and their source evidence are held by Russell Jones under the designated restricted private-audit custody, with verified SHA-256 copies. These public records include safe summaries only. The initial audit publication implemented no repairs. The later ordinary remediation routing is recorded below; private reports remain outside this publication.

Publication self-review checked record/index agreement, historical evidence retention, stable finding IDs, relative links and the public-only file scope. The focused architecture checks passed 134 tests and 1,169 assertions after restoring the earlier CLI and Foundation evidence links. All five ledger validators and the retained API-shape probe passed. No runtime behavior or enduring specification changes are included.

## Ordinary remediation issue routing (2026-10-19)

All 20 ordinary findings now have filed destinations: 16 new issues and four findings assigned to three existing issues. The routing review checked 200 open issues, 15 open pull requests and five targeted closed-issue searches. The open pull requests were dependency updates; closed feature work is retained as historical context rather than treated as current repair evidence.

| Package | Finding | Remediation issue | Routing |
| --- | --- | --- | --- |
| audit | AUD-CONTRACT-001 | [#3184](https://github.com/waaseyaa/framework/issues/3184) | Existing owner |
| audit | AUD-DOC-001 | [#3212](https://github.com/waaseyaa/framework/issues/3212) | New bounded issue |
| audit | AUD-COMPONENT-001 | [#3015](https://github.com/waaseyaa/framework/issues/3015) | Existing owner |
| entity-storage | EST-DOC-001 | [#3213](https://github.com/waaseyaa/framework/issues/3213) | New bounded issue |
| foundation | FND-CONTRACT-001 | [#3123](https://github.com/waaseyaa/framework/issues/3123) | Existing owner |
| foundation | FND-DIAGNOSTIC-001 | [#3214](https://github.com/waaseyaa/framework/issues/3214) | New bounded issue |
| foundation | GRP-LIFECYCLE-002 | [#3123](https://github.com/waaseyaa/framework/issues/3123) | Existing owner |
| foundation | FND-HTTP-001 | [#3215](https://github.com/waaseyaa/framework/issues/3215) | New bounded issue |
| foundation | FND-HTTP-002 | [#3216](https://github.com/waaseyaa/framework/issues/3216) | New bounded issue |
| foundation | FND-MONITOR-001 | [#3217](https://github.com/waaseyaa/framework/issues/3217) | New bounded issue |
| foundation | FND-LOG-001 | [#3218](https://github.com/waaseyaa/framework/issues/3218) | New bounded issue |
| foundation | FND-GUIDANCE-001 | [#3219](https://github.com/waaseyaa/framework/issues/3219) | New bounded issue |
| cli | CLI-INPUT-001 | [#3220](https://github.com/waaseyaa/framework/issues/3220) | New bounded issue |
| cli | CLI-IO-002 | [#3221](https://github.com/waaseyaa/framework/issues/3221) | New bounded issue |
| cli | CLI-DOC-001 | [#3222](https://github.com/waaseyaa/framework/issues/3222) | New bounded issue |
| cli | CLI-NAV-001 | [#3223](https://github.com/waaseyaa/framework/issues/3223) | New bounded issue |
| cli | CLI-STRUCT-001 | [#3224](https://github.com/waaseyaa/framework/issues/3224) | New bounded issue |
| cli | CLI-COMPONENT-001 | [#3225](https://github.com/waaseyaa/framework/issues/3225) | New bounded issue |
| user | USER-CONTRACT-001 | [#3226](https://github.com/waaseyaa/framework/issues/3226) | New bounded issue |
| user | USER-DOC-001 | [#3227](https://github.com/waaseyaa/framework/issues/3227) | New bounded issue |

The issue bodies retain the recorded acceptance and positive controls. Informational findings require the stated bounded review; they are not blanket replacement or extraction decisions. Issue filing implements no repairs, adds no runtime qualification and does not advance assessment or repair-ready milestones. **User remains in progress**; its ordinary remediation is planned while required assessment verification remains incomplete.


