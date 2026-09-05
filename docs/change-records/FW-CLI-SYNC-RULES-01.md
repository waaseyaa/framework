# FW-CLI-SYNC-RULES-01 — package-owned CLI rules

Date: 2026-09-04. Forge mirror: #2832. Consumer evidence: Content Pipeline #36 and accepted ADR-004.

## Design and scope

The real MiscBServiceProvider wires sync-rules to an application-relative framework aggregate directory. Supported direct-package applications omit that package, although the required Foundation package ships the canonical three rule files. Resolve the source from the installed Foundation package, keep the application root as the output destination, and preserve the existing handler, options, output format and overwrite policy. No fallback source, application workaround, release, new dependency or new public symbol is needed.

The locator must follow the owning package's actual loaded location, when it resides outside the CLI package's parent; a sibling of the CLI package is sufficient only when both packages share a parent. Independent review confirmed that sibling traversal fails mixed or independently located package sources; the selected expression reflects the already-loaded Foundation ServiceProvider class and walks to its package root. Foundation's existing ServiceProvider class is an available owning-package anchor without introducing another public API.

## Plan and proof

1. Capture the real provider-wiring regression against the unchanged source in a fresh application root with neither framework nor application rules.
2. Select and implement the owning-package lookup; require the exact three filenames, 3/0/0 counts and no dry-run output directory.
3. Archive the candidate HEAD and install copied packages into disposable direct-profile and aggregate consumers. The direct profile is the accepted 23 runtime plus 5 development requirements; its source is this candidate, not the historical failing consumer cohort. Assert framework absence for the direct profile, installed Foundation resources, actual CLI output and dry-run nonpublication. Exercise missing and empty resources as negative controls.
4. Restore the obsolete lookup as a hostile rival and observe rejection. Independently review the fix and proof, run all publication gates on the final committed candidate, then qualify exact hosted checks and the governed merge.

## Ownership and evidence

Primary owns production, specification, CI, architecture wiring, verification and forge custody. A cheaper implementation worker owns the focused regression and packaged harness; another worker scouts the accepted consumer profile read-only. A stronger reviewer checks package topology and reviews independently. Shared mutations are serialized.

The original provider regression failed with exit 1 and the absent vendor/waaseyaa/framework/.claude/rules diagnostic (one test, three assertions). Retained log: work/2832/miscb-sync-rules-red.log. Subsequent results and exact candidate/merge identity are recorded in review and delivery evidence; this initial record makes no final-green claim.

Primary independently killed the original aggregate-lookup rival (missing source, exit 1) and the CLI-sibling rival (decoy rule inventory, exit 1), restored exact source bytes, and reran both tests green (2 tests / 23 assertions). The topology proof selects resources only; it does not expand the separate single-checkout provenance policy. Initial Unit passed 14,272 / 241,115 before the topology test was added; Integration passed 2,312 / 11,741 and initial full preflight passed 41 gates. These are development results, not final committed-head qualification.

Archive inspection also found that the old framework-root rules directory contains no waaseyaa-*.md files: an aggregate consumer can exit successfully with zero synchronized rules. The fixed aggregate control must report the three canonical Foundation files; preserving aggregate support does not mean preserving that empty result. The initial packaged attempt failed Composer schema validation because the draft aggregate fixture encoded empty require-dev as an array; it is harness-quality evidence, not a production RED.

The repaired packaged harness reached the real original defect on archived 551a438fa4968dc957c740109e9a42afa9b78c07: both consumers installed, provenance checks reported 46 direct-profile and 64 aggregate Waaseyaa packages, and the direct command exited nonzero naming the absent aggregate source. Retained evidence: work/2832/check-cli-sync-rules-oldhead-product-red.log. The earlier aggregate-only rejection log separately proves its zero-file success and is not substituted for this direct regression. Independent harness review required explicit expected exit/counts for corrupted-source controls so unrelated failures cannot satisfy them. Precommit Architecture refused four dirty-byte archive proofs as designed; final qualification runs after committing.
