# FW-PROJECT-INITIALIZER-01 — compose the canonical fresh-project phases

Status: accepted bounded design for Framework #2664 Lane A, amended by root integration review on 2026-09-07; implementation and qualification are pending.

Anchor mirror: [waaseyaa/framework#2664](https://github.com/waaseyaa/framework/issues/2664)

Implementation baseline: accepted `main` commit `d1918a2941acebfb889970df310cd466c65d9246`, inspected by exact Git object identity on 2026-09-07. The command, handler, kernel, entrypoint, and golden-path sources used for this design are unchanged from the reviewed `0e29b7900b68ccbb0b6c1a0d6924914642b3d98f` source; the intervening `cli-kernel.md` change concerns field-scaffold projection only.

## Record history

- Originally approved portable record SHA-256 (2026-09-07): `913da80a2aba8e084c1156c6b75fa23ce18424541923fa5f2934af4bae1b5a9b`.
- Root integration amendment ratified 2026-09-07 (`2664-root-review-amendment.md`). The sections below on canonical object embedding, child cleanup failure, closed error roster, focused tests, and shared-file ownership supersede the conflicting guarantees in that approved copy. Unrelated accepted contract is unchanged.

## Outcome and bounded scope

Add one `project:init` command that composes the two already-supported fresh-project phases in order:

1. `site:init`, using its published option and interaction contract; then
2. `install:init`, only after the first child exits successfully.

The command is orchestration only. It does not parse answer or seed documents, resolve presets, compile artifacts, inspect generated state, apply migrations, activate configuration, or add an orchestration ledger. Those responsibilities stay in the two child commands.

This slice does not implement `project:init --upgrade`, `ai:update`, `ai:verify`, a Composer update hook, legacy generated-state transition, generated-output removal, or final verification. Those remain explicit residual acceptance of Framework #2664, governed by ADR-025 and the `site-golden-path.md` and `cli-kernel.md` contracts; the linked #2660 whole-client verification/removal outcome also remains open. `project:init --upgrade` is not registered in this slice; invoking it is an unknown-option usage failure before any child starts. A successful plain `project:init` invocation proves only that these two fresh-project phases completed and cannot close #2664.

## Source-backed constraints

- `docs/specs/site-golden-path.md` fixes the consumer lifecycle as create → `site:init` → `install:init` → `composer site-verify` → serve. This command composes phases two and three; it does not absorb verification.
- `SiteServiceProvider::siteInitCommand()` is the single `site:init` definition. Its options are `--answers`, `--decision-receipt`, `--preset`, `--project-root`, `--dry-run`, `--json`, and `--yes`/`-y`.
- `site:init` is boot-free and must not open the database. `ConsoleKernel` constructs its command directly before framework boot.
- `install:init` has no command-specific options. `ConsoleKernel` routes it through `bootForSchemaSync()`, where `MigrateServiceProvider` builds migrations, schema synchronization, genesis activation, and resolved database authority.
- `install:init` is the only canonical materialization phase and is idempotent. It must run after site artifacts exist so recipe-declared entity types participate in the same schema synchronization pass.
- `WaaseyaaConsoleApplication` maps every non-zero command result except `130` to shell exit `1`; `130` is preserved. Machine output therefore carries phase identity and observed child exit rather than inventing finer meaning from human text.
- `packages/cli/bin/waaseyaa` requires the current working directory to be a project root containing `composer.json`, then loads that root's `vendor/autoload.php` and gives the same root to `ConsoleKernel`.

The separate boot modes make a process boundary load-bearing. Running `site:init` and then directly resolving `InstallInitHandler` inside one boot-free application would bypass `bootForSchemaSync()`. Booting the parent first would open the database before `site:init`. Two executions of the installed CLI preserve both existing entrypoint contracts without a second boot implementation.

## Command contract

`project:init` registers these command-specific options and no others:

| Option | Parent behavior | Child argv |
|---|---|---|
| `--answers=PATH` | Accepted verbatim; semantic validation stays in `site:init`. | Forward to `site:init` as one argument/value pair. |
| `--decision-receipt=PATH` | Accepted verbatim; receipt decoding and binding stay in `site:init`. | Forward to `site:init`. |
| `--preset=minimal|editorial` | Accepted as a string; the parent owns no preset roster. | Forward to `site:init`, which applies its closed roster. |
| `--project-root=PATH` | Resolve once to the existing canonical project directory described below. | Use as child cwd and forward the canonical absolute value to `site:init`; `install:init` has no such option. |
| `--dry-run` | Preview only the site phase. Never start `install:init`. | Add `--dry-run` to `site:init`. |
| `--json` | Emit one parent JSON document; no child bytes escape directly. | Add `--json` to `site:init`; capture both children. |
| `--yes`, `-y` | No independent authorization meaning. | Forward to `site:init`; that command retains confirmation policy. |

Symfony's global `--no-interaction`/`-n` state is observed through `SymfonyCommandIO::isInteractive()`. The child receives `--no-interaction` whenever the parent is non-interactive or in JSON mode. A non-JSON invocation keeps attached streams even when it is non-interactive; the explicit option, rather than closing stdin, prevents prompting. Raw parent argv, unknown options, verbosity flags, and environment-derived strings are never copied into a child command.

The parent does not duplicate `site:init`'s input rules. Consequently JSON without answers, non-interactive publication without `--yes`, an invalid preset, and an invalid decision receipt fail through the exact child definition, and `install:init` is not invoked.

### Project-root resolution

The handler starts from the `ConsoleKernel` project root. If `--project-root` is relative, it is resolved relative to that root, not the caller's later-mutated cwd. The resulting path must canonicalize to an existing directory containing all of:

- a regular `composer.json`;
- `vendor/autoload.php`; and
- the fixed installed entrypoint `vendor/bin/waaseyaa`.

The entrypoint may be a Composer proxy or symlink because external path-repository layouts are supported. The command path is constructed from the canonical root and is never accepted as an option. Answer and receipt paths are not opened by the parent and retain `site:init`'s rule that relative paths resolve beneath the selected project root. The parent process never calls `chdir()`.

## Ordered execution

The handler constructs only these commands, using array argv and the selected root as explicit cwd:

```text
[PHP_BINARY, <root>/vendor/bin/waaseyaa, site:init,
  [--answers, VALUE], [--decision-receipt, VALUE], [--preset, VALUE],
  --project-root, <canonical-root>, [--dry-run], [--json], [--yes],
  [--no-interaction]]

[PHP_BINARY, <root>/vendor/bin/waaseyaa, install:init,
  [--no-interaction]]
```

Square brackets denote conditional elements, not shell syntax. Each option value is a separate argv member. The parent never concatenates a command string.

Execution rules:

1. Validate the root and executable before either phase.
2. Start `site:init` in the I/O mode below.
3. On exit `0`, either:
   - for `--dry-run`, report that `install:init` was not run and return `0`; or
   - start `install:init` in a new process.
4. On any non-zero site exit or runner failure, record/report the observed phase and stop. Never start `install:init`.
5. Return the observed install exit after it terminates.

There is intentionally no cross-phase rollback. If site publication succeeds and installation fails, the canonical site transaction remains committed. A retry runs the same site command, which is idempotent for unchanged inputs, then retries the idempotent installation phase. A parent-owned journal would become a second authority and is forbidden.

## Process and I/O contract

Add a project-initializer-specific runner interface; do not broaden the admin npm runner or add `symfony/process` to the production dependency graph. Its production implementation uses `proc_open()` with an argv array, `bypass_shell`, explicit cwd, and inherited environment (`null` environment argument). It returns a small result containing the observed exit code and, in capture mode only, bounded stdout/stderr. It assigns no semantic status from output text.

Two modes are required:

- **Attached mode** for non-JSON execution: child stdin/stdout/stderr are the parent's actual streams so `site:init` remains genuinely interactive and human output is preserved. Non-interactive mode adds `--no-interaction`, so an absent TTY cannot silently select prompt defaults.
- **Captured mode** for `--json`: stdin is the platform null device, stdout and stderr are drained concurrently through non-blocking Unix pipes or the bounded Windows capture transport below, and no child bytes are emitted until the parent writes its one JSON document.

The ratified bounded defaults follow the existing production runner precedent: 900 seconds per child and 16 MiB per stream in captured mode, both constructor-injectable for focused tests. Exactly-at-limit output is accepted. The first byte beyond a cap terminates the child and drains/cleans resources. Timeout sends termination, waits only a bounded grace interval, escalates to a platform-appropriate hard stop, and never polls forever.

The runner owns the active child handle. Cleanup after timeout, output overflow, or a catchable parent exception/cancellation attempts graceful stop, bounded terminal observation, platform-appropriate hard stop, and bounded terminal observation again. `proc_close()` is permitted only after terminal confirmation. PHP 8.5's `proc_open` resource destructor uses nonblocking cleanup when references are dropped; explicit `proc_close()` waits. Do not hide a blocking `proc_close()` in `finally` after failed terminal confirmation — release the handle without closing when confirmation fails.

If terminal confirmation fails, stop initialization and never start the next phase. Report `PROJECT_INIT006_CHILD_CLEANUP_FAILED` with the exact owned child PID and a bounded, non-sensitive diagnostic. Do not claim timeout cleanup or no-orphan success when confirmation fails. Preserve the initiating `PROJECT_INIT002_CHILD_TIMEOUT` or `PROJECT_INIT003_CHILD_OUTPUT_LIMIT` result when termination was triggered by those conditions even if cleanup confirmation also fails. When a catchable parent exception or cancellation triggered cleanup, successful confirmation still propagates that original exception; failed confirmation returns `006` while retaining the initiating exception class in the bounded diagnostic.

The ordinary no-orphan acceptance applies to successful cleanup under the supported OS primitives. Failed termination is an explicit serious operational failure requiring remediation, never a successful command result. Do not kill by process name, target unrelated processes, retry the phase, or add a general runner.

A catchable user interrupt may be reported as `130` only when the runner actually observes that interrupt from the child; runner errors return `1` and must not masquerade as user cancellation. Abrupt uncatchable termination such as process kill or host loss cannot be repaired by PHP `finally` and is outside this claim.

Attached execution cannot impose an output cap without breaking terminal semantics; its fixed trusted child command and timeout are the containment boundary. No output is stored, logged, or inspected by the parent in that mode.

## JSON output

`project:init --json` emits exactly one newline-terminated canonical JSON object in this accepted closed shape:

```json
{
  "schema": "waaseyaa.project_init_result",
  "version": 1,
  "status": "previewed|completed|failed",
  "phases": {
    "site": {
      "command": "site:init",
      "status": "succeeded|failed",
      "exit_code": 0,
      "result": {},
      "stderr": ""
    },
    "install": {
      "command": "install:init",
      "status": "not_run|succeeded|failed",
      "exit_code": null,
      "stdout": "",
      "stderr": "",
      "not_run_reason": "dry_run|site_failed|null"
    }
  },
  "errors": []
}
```

This example shows the member roster, not literal union-string values or serialization order. `Waaseyaa\SiteContract\CanonicalJson` recursively sorts object keys; tests pin that canonical ordering and must not expect the visual order used in this example.

The site child's stdout must decode to exactly one JSON object. The parent embeds its decoded semantic value unchanged under `phases.site.result` using `json_decode(..., false)` so empty objects and numeric-key objects remain objects through `CanonicalJson::encode()`; it does not reinterpret evaluation, plan, refusal, or receipt members. Empty, malformed, multiple, or trailing non-whitespace output is `PROJECT_INIT004_CHILD_PROTOCOL_INVALID`, fails the site phase, and prevents installation. Captured site stderr is retained as diagnostic text. Execution receipt ids and timestamps remain execution evidence; neither parent embedding nor canonical key ordering makes separate invocations byte-identical.

`install:init` has no JSON contract at the baseline. Its captured stdout/stderr remain opaque bounded diagnostic strings; only its exit code determines success. The parent never regexes authority ids, paths, generation ids, or error wording into stable fields. This preserves current operator evidence without claiming a new installation-result schema.

Runner-level errors use this closed list:

- `PROJECT_INIT001_CHILD_START_FAILED`
- `PROJECT_INIT002_CHILD_TIMEOUT`
- `PROJECT_INIT003_CHILD_OUTPUT_LIMIT`
- `PROJECT_INIT004_CHILD_PROTOCOL_INVALID`
- `PROJECT_INIT005_INVALID_PROJECT_ROOT`
- `PROJECT_INIT006_CHILD_CLEANUP_FAILED`
- `PROJECT_INIT007_CHILD_CAPTURE_FAILED`

Errors name the phase and code in the stable portion. `PROJECT_INIT006_CHILD_CLEANUP_FAILED` additionally carries `child_pid` (exact owned child PID) and `diagnostic` (bounded, non-sensitive). They do not include environment contents, raw argv, exception traces, or arbitrary filesystem content. In plain mode, existing child diagnostics remain authoritative. The parent adds `Dry run complete; install:init was not run.` after a successful preview, and reports `PROJECT_INIT006_CHILD_CLEANUP_FAILED` with the exact owned child PID and bounded diagnostic if terminal confirmation fails in either phase.

## Canonical object embedding (integration amendment)

Extend the existing `Waaseyaa\SiteContract\CanonicalJson` private normalization to recursively normalize nested `stdClass` members, including objects nested in lists. Keep `encode(array)` and all existing array/list semantics and JSON flags unchanged. Sort object keys with the same byte-string ordering, retain objects as objects (including empty and numeric-key objects), and do not mutate caller objects. Do not add another canonical JSON engine or invoke arbitrary object methods. Existing receipt/digest array encodings must remain byte-identical.

## Native Windows capture (integration amendment)

Captured Windows execution uses direct argv proc_open with two atomically
created random temporary output files outside the project and separate parent
read handles. Immediately after spawn, unlink the names while open handles
continue receiving output. Attached streams and Unix captured pipes retain
their existing transport; no shell or additional dependency is introduced.

Poll file sizes at intervals no greater than 10 ms while waiting; observe the
deadline between reads. Read increments are at most 64 KiB. Reject size greater
than the configured per-stream cap before reading excess, including a final
size observation after child exit. Exactly-at-limit output remains accepted.
The cap bounds retained/returned bytes per stream, not total PHP memory or
physical spool usage: a child can write excess bytes before observation and
termination. Unlinked output bodies persist only until the remaining handles
close. Named output cleanup must cover every setup and spawn failure path.

Pre-spawn capture setup failure remains PROJECT_INIT001_CHILD_START_FAILED.
After successful spawn, unusable capture (including failed name removal or
read/seek/stat failure) is PROJECT_INIT007_CHILD_CAPTURE_FAILED after confirmed
cleanup. This distinction records that the phase may already have executed;
the parent must not imply it never started. If terminal confirmation fails,
PROJECT_INIT006_CHILD_CLEANUP_FAILED takes priority and retains initiating 007
in its bounded diagnostic. No next phase starts after either failure.

Root owns this amendment. The Windows implementation owner may change only
the runner, its result type, and runner tests in the existing initializer lease.
Native tests must exercise the actual production runner; scratch transport
probes alone do not qualify the feature. Full installed lifecycle and hosted
supported-platform evidence remain separate requirements.

## First discriminating tests

Write these RED before production code.

1. **Exact composition:** a fake runner records calls. Every supported parent option maps to the exact site argv above; the second call is exactly `install:init`; cwd and executable bind to the same canonical root. The handler contains no site parser, preset resolver, renderer, migration, schema, activation, or generated-state type.
2. **Dry-run containment:** `project:init --dry-run` invokes one `site:init --dry-run` child, returns its exit, reports `install:init` as `not_run/dry_run`, and creates no second call even when `--yes` is also present.
3. **Stop on failure:** site exit `1`, site interrupt `130`, start failure, timeout, overflow, invalid JSON, and cleanup failure each prove no install call. Install non-zero/`130` is returned only after a successful site phase.
4. **Interaction:** an interactive plain invocation attaches all three streams and does not append `--no-interaction`. A non-interactive plain invocation still attaches all three streams, including readable stdin, but passes `--no-interaction` so the child does not solicit input. JSON mode passes `--no-interaction`, uses the null device for stdin, and captures both output streams. An interactive no-answers process reaches the real `site:init` prompt rather than selecting defaults.
5. **Machine envelope:** successful preview, complete run, site refusal, and install failure each produce one parseable object with the exact member roster/order and phase exits. Install diagnostic text is transported but never parsed into status. Child output cannot escape before or after the object. Nested empty objects and numeric-key objects in the embedded site result remain JSON objects in the parent output.
6. **Root and injection boundary:** relative root canonicalization is stable; missing `composer.json`, autoload, or vendor entrypoint refuses before launch; paths/options containing spaces and shell metacharacters remain one argv member; no shell process appears. The parent cwd stays unchanged.
7. **Runner bounds and custody:** a real finite child proves concurrent stdout/stderr drain, exact-cap success, one-byte-over termination, finite timeout for a child that ignores graceful termination, confirmed cleanup, and accurate exit recovery after `proc_get_status()`. Linux cases exercise catchable parent cancellation/exception cleanup and deterministic cleanup-failure control flow without a live orphan. Native Windows exact-PID custody remains integration-owner evidence unless an executable Windows-only fixture is available on that host; Linux execution does not qualify Windows.
8. **Retry truth:** simulated site success/install failure followed by retry proves the orchestrator keeps no private completion flag, calls site again, and reaches install. Existing `SiteInitializationService` and `InstallInitHandler` idempotence tests remain the semantic authorities.
9. **Kernel seam:** a real `project:init --dry-run` process on a scratch project proves no database or SQLite sidecar is created. A command-definition assertion pins the exact option roster and proves `--upgrade` is unknown with zero child launches.
10. **No profile fork:** at the same source identity and project-state snapshot, the same answer/receipt inputs run directly through `site:init --dry-run --json` and through the project initializer's site phase produce the same semantic `evaluation.plan`, plan digest, project-state digest, per-target statuses, set delta, and refusals. The assertion excludes execution result/receipt ids, timestamps, and full independently encoded envelopes; those are not reproducible plan identity.
11. **Focused fresh lifecycle:** in an isolated scratch project with dependencies already present, plain non-interactive `project:init --answers=... --yes` creates the ordinary site artifacts, then the same database/configuration state as direct `install:init`; repeating it is successful and does not mint a second configuration generation.

The process tests use existing candidate-local dependencies; they do not perform Composer installation. Full suite, packaged installation, hosted checks, and exact-head qualification remain integration evidence after implementation review.

## File ownership and integration boundary

Lane A may own these new files:

- `packages/cli/src/Handler/ProjectInitHandler.php`
- `packages/cli/src/ProjectInit/ProjectInitProcessRunnerInterface.php`
- `packages/cli/src/ProjectInit/ProcOpenProjectInitProcessRunner.php`
- `packages/cli/src/ProjectInit/ProjectInitProcessResult.php`
- `packages/cli/tests/Unit/Handler/ProjectInitHandlerTest.php`
- `packages/cli/tests/Unit/ProjectInit/ProcOpenProjectInitProcessRunnerTest.php`
- `packages/cli/tests/Integration/ProjectInitProcessTest.php`

Lane A may also update, as ratified by root integration amendment:

- `packages/site-contract/src/CanonicalJson.php`
- `packages/site-contract/tests/Unit/CanonicalJsonTest.php`

The integration owner retains shared custody of:

- `packages/cli/src/Provider/SiteServiceProvider.php`, which exposes one static `projectInitCommand($projectRoot)` definition and includes it in ordinary discovery;
- `packages/foundation/src/Kernel/ConsoleKernel.php`, which recognizes `project:init` on the boot-free seam and adds that same command definition;
- `packages/foundation/tests/Unit/Kernel/ConsoleKernelTest.php`;
- `packages/cli/tests/Unit/Provider/ProjectInitCommandDefinitionTest.php`;
- `docs/specs/cli-kernel.md`, `docs/specs/site-golden-path.md`, the #2664 fragment, and any architecture/public-surface roster required by the actual diff.

No Composer dependency or package-layer change is authorized. The runner is CLI-local and invokes only the fixed installed Waaseyaa entrypoint. Lane A does not edit `SiteInitHandler`, `InstallInitHandler`, `SiteInitializationService`, `MigrateServiceProvider`, the skeleton Composer scripts, or AI/Bimaaji code.

## Accepted decisions and remaining proof requirements

The process boundary, closed option forwarding, dry-run stopping before installation, lack of a cross-phase transaction, and separate final verification follow existing source contracts. They are not open product decisions.

Integration review ratified the JSON member names/error codes (including `PROJECT_INIT006_CHILD_CLEANUP_FAILED`) and the 900-second/16-MiB runner bounds. Changing them must update this record and its exact tests.

Focused native PHP 8.5 checks against the candidate production runner establish responsive timeout, exact-cap capture, overflow refusal, post-spawn capture failure, removal of named capture files, and exact child-PID termination after catchable parent cancellation. The retained native harness is content-bound as SHA-256 `70567d721a12595071a7b440941de0a346f7eb1882926ac92561e99482010189`. A subsequent bounded native acceptance run also proves forced-006 diagnostics with owned PID and initiating timeout/capture failure, actual provider/handler phase ordering, dry-run stopping before install, and literal argument forwarding. Its receipt SHA-256 is `de0c6e923a6d6ae9f93a2ce3773a977315b19b367d3db485539ab5fd130c9a10`; runner source SHA-256 is `3f3bb53a6c266ac49df773c7f255ceca3294466f9d1a9cf052dd9b5993c7e343`. The phase tests use finite stub installed children, so these source-bound checks do not establish a full Windows packaged lifecycle or hosted qualification; those remain explicit verification requirements. Linux custody proofs do not substitute for Windows evidence. An earlier composite cancellation assertion failed without retaining its component observations; it did not establish a listed or executing PID. Three retained diagnostic attempts subsequently observed terminal process status before return, no ticker progress afterward, and no exact PID in timed tasklist samples through 2250 ms. The original composite failure remains unclassified; it is not reported as a production termination failure or silently omitted.

The following remain unresolved outside Lane A and must not be inferred from this command: the `--upgrade` catalog and apply contract; legacy generated-state transition; AI unit ids/update/verify/removal; Composer post-update behavior; and whether a future broader orchestrator ever invokes `composer site-verify`. Until those contracts land, unsupported options/modes fail explicitly rather than returning a stub success.

## Review and completion evidence

This record authorizes only the bounded implementation files named above. Implementation completion requires the discriminating focused tests, independent review of the actual immutable delta, relevant spec/change-fragment checks, exact-head hosted checks, and a current qualification receipt. Landing this Lane A record or command remains **Part of #2664** and does not close the issue's upgrade or AI lifecycle acceptance.
