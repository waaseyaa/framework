# Qualification and the next units

An inventory row is not a passed qualification cell. Each behavioral result needs
the source/lock, command and exit, actual composition, host, backend, lifecycle,
positive/negative controls, and explicit exclusions. Keep logs bound to the tested
candidate; a later documentation-only commit does not silently become that SHA.

## Coverage dimensions

| Dimension | Required variants | A0 evidence boundary |
|---|---|---|
| Installation | core / cms / full / root; standalone optional packages; admin | Manifest closures enumerated, not installed-consumer certification |
| Host | Skeleton, root application, extension-host application | Actual discovery/wiring qualification remains distinct |
| Execution | HTTP, CLI, repeated HTTP/queue workers, scheduled jobs, streaming | Source entrypoints mapped; only bounded checkpoint execution carried forward |
| Storage | sql-blob, sql-column; relevant in-memory SPI tests; supported adapters | Both SQL layouts mandatory; test-only backends are not automatically production-supported |
| Lifecycle | Fresh, upgrade, replay, preview/status, interruption, deploy/restore | Do not substitute fresh success for upgrade/recovery correctness |
| Platform | Supported PHP/Node/frontend and Linux/Windows/worker hosts | Census reproduced on Windows; isolated autoload probe on Linux; no Pi qualification |
| Extensions | Third-party implementors and split-package consumers | Root autoload/test setup cannot establish downstream availability |

## Carried behavioral checkpoints

These are summaries of earlier audit work, not tests newly run during A0 adoption.
The relevant issue threads retain the finding/reproduction ownership. Independent
review must inspect that evidence before marking a broader matrix cell passed.

| New checkpoint label | Original local artifact label | Boundary / tracking | Important limit |
|---|---|---|---|
| CP01 | B01 | B01; [A2 #2722](https://github.com/waaseyaa/framework/issues/2722), #2728 | Baseline PRE_DELETE failure; post-baseline #2735 fixes it. #2733/#2734 remain separate |
| CP02 | B02 | B02; [A3 #2723](https://github.com/waaseyaa/framework/issues/2723) | Real bounded pipeline/scoped-queue checks, not complete repeated kernel/actor-resolver qualification; #2729 is a separate full-boot finding |
| CP03 | B03 | B03; [A5 #2725](https://github.com/waaseyaa/framework/issues/2725), #2548/#2549 | Real preparer/installer API probes; caught-exception recovery is not process death/power loss; Windows installer failures remain visible |
| CP04 | B04 | B04; [A1 #2721](https://github.com/waaseyaa/framework/issues/2721), #2730/#2731 | Replay and rollback composition, not all backend/deployer combinations |
| CP05 | B05 | B04; A1 #2721, #2732/#2682 | Real SQLite sql-blob/sql-column fresh and populated upgrade probes; not full installed-host boot and not async B05 |

CP03 explicitly found unsafe schema/graph and identity handoff cases, while some
installer recovery controls passed. Keep those outcomes separate. Likewise CP05
did not reproduce the older VARCHAR allegation in #1625; it did find current
preview/diagnostic disagreement. A review records safe controls as well as defects.

## Public test-directory declarations: bounded resolution experiment

The frozen public map has four entries whose declarations are outside `src`.
`tools/a0-autoload-probe.php` instantiates an isolated Composer ClassLoader per
package, adds only its declared PSR-4 maps, and calls `findFile()`. It does not
register loaders, load framework classes, install split artifacts or boot a kernel.

On baseline source `50750231a8036ae7afc68416fed8ea271e47159f`, Linux PHP 8.5.9:

| Public-map symbol | Runtime map resolves | Own package root with dev map resolves | Interpretation |
|---|---|---|---|
| `Waaseyaa\Entity\Testing\Translation\TranslatableEntityContractTest` | No | Yes | Development-only mapping; #2729 already highlights the unused published contract |
| `Waaseyaa\Migration\Testing\SourceConformanceTestCase` | No | Yes | Development-only conformance contract promised to plugin authors |
| `Waaseyaa\Migration\Testing\DestinationConformanceTestCase` | No | Yes | Same distribution boundary |
| `Waaseyaa\CLI\Io\StdinSource` | No | No | Declared under `tests/Io`, outside the declared `CLI\Tests` prefix; review public-map classification and consumers |

The migration authoring guide explicitly calls the harness autoload-dev and asks
third-party plugins to subclass it. This experiment does **not** prove whether an
independent consumer's explicit bootstrap recipe works, or whether a published
artifact includes the files. Those are the next A6 tests. It does demonstrate why
token resolution and root-monorepo tests alone cannot establish consumer reachability.

Reproduce without giving the probe a root-monorepo registered autoloader:

```sh
php tools/a0-autoload-probe.php /absolute/baseline-checkout \
  data/public-surface.json /absolute/composer-install/vendor/composer/ClassLoader.php
```

The last argument loads only Composer's resolver implementation. No framework
autoload.php is required. The file path itself is an input; use the lock-matched
local Composer installation for a repeated comparison.

## Next bounded work, without another umbrella project

1. **A6 consumer proof:** install the relevant package artifacts into independent
   temporary consumers, following their documented test-authoring recipe. Verify
   both production boot without PHPUnit and extension test execution with it.
   Decide mapping/packaging/documentation/classification only after that evidence.
   Keep the existing #2729 runtime-wiring defect separate from this distribution question.
2. **A3/A2 transaction authority:** take #2734 through the real fenced effect path,
   covering save and delete, commit and outer rollback, and per-event failure. This
   is independent of a migration PR and has a concrete live caller.
3. **A1/A5 schema-to-deployment proof:** qualify materializer/compiler/ledger and
   artifact handoff together for each SQL layout and fresh/upgrade lifecycle. Reuse
   #2548/#2549 and current schema findings; do not infer safety from separate halves.
4. **A0 review and historical closure decision:** review all allocations and the
   missing-original limitation; do not close A0 merely because this document merges.
5. **A7 synthesis:** classify repeated root causes, then propose keep/remove/replace/
   consolidate decisions with proof obligations and dependencies, not a blanket rewrite.

These are recommendations for the existing program, not authorization to implement
or merge them. Active PRs remain individual exact-head review candidates. #2706's
green CI establishes readiness for review, not permission to merge it or its stack.
