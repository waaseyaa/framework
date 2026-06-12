---
affected_files: []
cycle_number: 2
mission_slug: live-entity-validation-key-protection-01KTWQT3
reproduction_command:
reviewed_at: '2026-06-12T02:59:26Z'
reviewer_agent: unknown
verdict: rejected
wp_id: WP03
---

# WP03 Review — Cycle 1 (changes requested)

Reviewed commit `57e63f8e8` against `contracts/tool-refusal.md`, task DoD, and research D3/D4/D6. All four gates run locally from the lane-a worktree and green: `./vendor/bin/phpunit packages/ai-tools/tests/` (36 tests, 122 assertions, OK), `composer phpstan` (no errors), `composer cs-check` (0 files flagged), `bin/check-dead-code` (no new findings). Diff scope is exactly the 7 owned files. The implementation itself is correct — this rejection is a single missing test required by the DoD.

**Issue 1 — DoD checkbox "Check order verified by test" is unmet: no test proves an access-denied caller with a refused key gets the access error, not the refusal (contract clause 4).**

The code order is correct (in `EntityCreateTool::execute()` the guard runs after `requireCreateAccess`, lines 71–85; in `EntityUpdateTool::execute()` after `requireEntityAccess`, lines 79–91), but no test pins it:

- Every test in `packages/ai-tools/tests/Unit/Entity/EntityToolKeyRefusalTest.php` and `tests/Integration/EntityToolRefusalDispatchTest.php` runs WITHOUT an access handler attached (`setAccessHandler` never called), so access always passes before the refusal fires.
- Every denial test in `packages/ai-tools/tests/Unit/Entity/EntityToolAccessTest.php` uses a clean payload (`['title' => ...]`) with no refused key.
- The WP03 edit to `create_is_policy_gated()` (EntityToolAccessTest.php:186-193) removed the pre-set `'id' => '2'` from the **denied** call as well as the success call. Removing it from the success call was required by D3; removing it from the denied call was not — that payload was the one incidental witness that denial wins over refusal, and the test would still have passed with it in place (access is checked first).

Why this matters: clause 4's parenthetical ("Refusal must not leak entity existence to callers lacking access") is the security property. The guard needs no loaded entity, so a future refactor hoisting it above the access check is plausible and would silently introduce the identity-probing leak — with the current suite still fully green.

**Fix** (any one of these satisfies the DoD; (a) is the minimal diff):

(a) In `EntityToolAccessTest::create_is_policy_gated()`, restore a refused key in the denied payload only, e.g. `['entity_type' => 'tool_test', 'values' => ['id' => '2', 'title' => 'Made']]`, keep asserting `assertSame('forbidden', $denied->summary)`, and add the negative assertion that the message is NOT the refusal shape (e.g. `assertStringNotContainsString('refused identity keys', $denied->content[0]['text'] ?? '')`). Do the same for an update denial (e.g. extend `update_is_forbidden_when_the_policy_denies_even_with_the_capability()` with `'langcode' => 'xx'` in values).

(b) Or add a dedicated test in `EntityToolKeyRefusalTest` that attaches the policy-denying access handler from `EntityToolAccessTest`, sends a payload containing a refused key on BOTH tools, and asserts the result is the access error (`forbidden` summary), never the refusal message, with no save/instantiation.

Both tools must be covered (the DoD ordering property applies to create and update). No production-code change is needed or wanted — `packages/ai-tools/src/Entity/*.php` are correct as committed.

---

No other findings. For the record, the rest of the checklist passed:

- Contract clauses 1–3, 5–9 all verified in code and covered by tests (refusal set incl. renamed `nid`/`revision_id` columns + literal floor; label/bundle pass; whole-write rejection with save-never-called / entity-unmutated / class-never-instantiated assertions via `CountingToolEntity::$constructed`; em-dash message with sorted keys; dry-run refusal on both tools; revision_log still writable; single seam — no `validate: false`, no tool-private validation; `EntityValidationException` caught before `\Throwable` with field-sorted, insertion-stable formatting via explicit index tiebreak; generic throwable arm unchanged).
- NFR-002: all five kinds (`id`, `uuid`, `revision_id`, `langcode`, `default_langcode`) loop-tested on BOTH tools.
- `AgentToolResult` public API untouched (file not in diff); structured payload uses the existing public constructor; first content block is `type: text` carrying the contract message.
- T014 dispatch-seam claim verified: `packages/mcp/src/Bridge/AgentToolRegistryBridge.php:66` and `packages/ai-agent/src/AgentExecutor.php:319,433` both dispatch via `$tool->impl->execute(...)` resolved from the registry — the integration test exercises the same seam.
- Style: strict_types, final, PHPUnit attributes, named args throughout.
