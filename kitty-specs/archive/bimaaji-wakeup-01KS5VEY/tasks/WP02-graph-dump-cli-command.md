---
work_package_id: WP02
title: CLI command — graph:dump
dependencies:
- WP01
requirement_refs:
- FR-005
- FR-006
- FR-011
- FR-012
- FR-013
- NFR-003
- NFR-004
- C-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T008
- T009
- T010
- T011
- T012
history: []
authoritative_surface: packages/bimaaji/src/Command/
execution_mode: code_change
owned_files:
- packages/bimaaji/src/Command/GraphDumpHandler.php
- tests/Integration/PhaseN/Bimaaji/GraphDumpCommandTest.php
tags: []
---

## Objective

Implement `GraphDumpHandler` — the thin CLI handler for `bin/waaseyaa graph:dump` — and
wire it into `BimaajiServiceProvider::nativeCommands()`. The command exposes three flags:
`--section=<key>` (scope to one section), `--format=json|yaml` (output format, default
`json`), and `--strict` (fail-fast on provider errors). Three CommandTester-based
integration tests cover the full-dump, scoped, and unknown-section paths.

## Context

WP01 left `BimaajiServiceProvider::nativeCommands()` returning `[]`. This WP fills that
stub. The discovery path (plan AD-03) relies on `HasNativeCommandsInterface` being detected
by `PackageManifestCompiler` — T004 in WP01 confirmed the interface FQCN and discovery
mechanism. The `GraphDumpHandler` resolves `ApplicationGraphGenerator` from the container
at dispatch time (not at bind time) so that `--strict` can be forwarded to the generator
constructor.

NFR-003 requires stable JSON output (same keys, same order across runs). This is achieved
by sorting section keys alphabetically in the handler before serialising. NFR-004 requires
fail-closed `--strict` output that names the failing provider FQCN and the underlying error.

C-002 places the command in `packages/bimaaji/src/Command/` — the handler class lives there.
The `CommandDefinition` registered in `nativeCommands()` references the handler by FQCN;
`CliKernelServiceProvider` constructs it from the container.

## Subtasks

### T008 — Verify `symfony/yaml` availability and finalise format plan

**Purpose:** Determine whether `--format=yaml` can be implemented without adding a new
`require` to `packages/bimaaji/composer.json`. Per plan R3, if `symfony/yaml` is not a
direct dep of bimaaji, demote YAML to a follow-up and keep JSON as the only beta format.

**Steps:**

1. Check direct and transitive availability:
   ```
   grep -n "symfony/yaml" packages/bimaaji/composer.json
   composer show symfony/yaml 2>/dev/null | head -3
   ```
2. Decision tree:
   - If `symfony/yaml` is already a direct dep of bimaaji → implement `--format=yaml`.
   - If `symfony/yaml` is available transitively (via foundation or another dep) → add it
     as an explicit direct dep in `packages/bimaaji/composer.json` under `require`, then
     implement `--format=yaml`.
   - If `symfony/yaml` is not available at all → implement `--format=json` only; add a
     `TODO: yaml follow-up` comment in the handler; note in PR description.
3. Run `composer check-composer-policy` after any `composer.json` edit.

**Files touched:**
- `packages/bimaaji/composer.json` — conditional: add `"symfony/yaml"` under `require` if
  it is available transitively but not declared directly.

**Validation:**
- Decision is documented in a comment in `GraphDumpHandler.php`.
- `composer check-composer-policy` exits 0.

---

### T009 — Create `GraphDumpHandler`

**Purpose:** Implement the command handler with `--section`, `--format`, and `--strict`
flag handling.

**Steps:**

1. Create `packages/bimaaji/src/Command/GraphDumpHandler.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace Waaseyaa\Bimaaji\Command;

   use Waaseyaa\Bimaaji\ApplicationGraphGenerator;
   use Waaseyaa\Bimaaji\ApplicationGraph;
   use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
   // Import the CLI IO/handler base — verify the actual base class:
   // grep -rn "class.*Handler\|CommandHandler" packages/cli/src/ | head -10

   final class GraphDumpHandler
   {
       public function __construct(
           private readonly ApplicationGraphGenerator $generator,
       ) {}

       public function __invoke(mixed $io): int
       {
           $section  = $io->getOption('section');
           $format   = $io->getOption('format') ?? 'json';
           $strict   = (bool) $io->getOption('strict');

           // Generate graph — strict flag controls fail-fast vs. lenient mode.
           // ApplicationGraphGenerator constructor takes $strict — check actual signature.
           $graph = $strict
               ? (new ApplicationGraphGenerator(/* providers */))->generate()
               : $this->generator->generate();

           // Scope to section if requested
           if ($section !== null) {
               $sections = $graph->getSections();
               if (!array_key_exists($section, $sections)) {
                   $available = implode(', ', array_keys($sections));
                   $io->error(sprintf(
                       'Unknown section "%s". Available sections: %s',
                       $section,
                       $available,
                   ));
                   return 1;
               }
               // Rebuild as single-section graph for serialisation
               $data = [$section => $sections[$section]->toArray()];
           } else {
               $raw  = $graph->toArray();
               // NFR-003: sort section keys for stable output
               ksort($raw['sections'] ?? []);
               $data = $raw;
           }

           $output = match ($format) {
               'yaml'  => $this->toYaml($data),
               default => json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
           };

           $io->writeln($output);
           return 0;
       }

       private function toYaml(array $data): string
       {
           // Only reached if symfony/yaml is available (T008 decision)
           return \Symfony\Component\Yaml\Yaml::dump($data, 6, 2);
       }
   }
   ```

   **Important:** Before finalising this skeleton, verify the actual `ApplicationGraphGenerator`
   API:
   ```
   grep -n "function generate\|function getSections\|function toArray" \
     packages/bimaaji/src/ApplicationGraphGenerator.php \
     packages/bimaaji/src/ApplicationGraph.php \
     packages/bimaaji/src/GraphSection.php 2>/dev/null
   ```
   Adjust the skeleton to match the real API. The `--strict` handling may need to pass
   a flag to an existing method rather than constructing a fresh generator — check if
   `generate(bool $strict = false)` is the signature.

2. Verify `$io` type — locate the actual IO interface used by command handlers in this
   framework:
   ```
   grep -rn "function __invoke\|IoInterface\|CommandIo" packages/cli/src/ | head -10
   ```
   Update type-hints in `GraphDumpHandler` accordingly.

**Files touched:**
- `packages/bimaaji/src/Command/GraphDumpHandler.php` — create

**Validation:**
- `composer cs-check` passes.
- `composer phpstan` passes.

---

### T010 — Wire `nativeCommands()` in `BimaajiServiceProvider`

**Purpose:** Register `GraphDumpHandler` as the `graph:dump` command via
`HasNativeCommandsInterface::nativeCommands()`.

**Steps:**

1. Open `packages/bimaaji/src/BimaajiServiceProvider.php` (created in WP01).
2. Locate the `nativeCommands()` stub (currently returns `[]`).
3. Replace with a real `CommandDefinition` for `graph:dump`. Verify the `CommandDefinition`
   constructor signature:
   ```
   grep -n "class CommandDefinition\|public function __construct" \
     packages/cli/src/CommandDefinition.php
   ```
   Common shape (adjust to actual):
   ```php
   public function nativeCommands(): array
   {
       return [
           new \Waaseyaa\Cli\CommandDefinition(
               name: 'graph:dump',
               description: 'Dump the application graph as JSON or YAML.',
               handler: GraphDumpHandler::class,
               options: [
                   new \Waaseyaa\Cli\Option(name: 'section', description: 'Scope to a single section key.', required: false),
                   new \Waaseyaa\Cli\Option(name: 'format', description: 'Output format: json (default) or yaml.', required: false, default: 'json'),
                   new \Waaseyaa\Cli\Flag(name: 'strict', description: 'Fail fast on provider errors.'),
               ],
           ),
       ];
   }
   ```
4. Add required use imports to `BimaajiServiceProvider.php`.
5. Bind `GraphDumpHandler` as a singleton in `register()` so the container can resolve it:
   ```php
   $this->singleton(GraphDumpHandler::class, function () {
       return new GraphDumpHandler($this->resolve(ApplicationGraphGenerator::class));
   });
   ```

**Files touched:**
- `packages/bimaaji/src/BimaajiServiceProvider.php` — edit (WP02's addition to WP01 file)
- `packages/bimaaji/src/Command/GraphDumpHandler.php` — add import if needed

**Validation:**
- After `bin/waaseyaa optimize:manifest`, running `bin/waaseyaa list` includes `graph:dump`.
- `composer phpstan` passes.

---

### T011 — Create `GraphDumpCommandTest` (integration tests)

**Purpose:** Cover FR-011, FR-012, FR-013 with three `CommandTester`-based test methods.

**Steps:**

1. Create `tests/Integration/PhaseN/Bimaaji/GraphDumpCommandTest.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace Waaseyaa\Tests\Integration\PhaseN\Bimaaji;

   use PHPUnit\Framework\Attributes\CoversClass;
   use PHPUnit\Framework\Attributes\Test;
   use PHPUnit\Framework\TestCase;
   use Symfony\Component\Console\Tester\CommandTester;
   use Waaseyaa\Bimaaji\Command\GraphDumpHandler;
   // Import the console application / command wrapper — verify:
   // grep -rn "class.*Application\|registerCommand" packages/cli/src/ | head -5

   #[CoversClass(GraphDumpHandler::class)]
   final class GraphDumpCommandTest extends TestCase
   {
       private CommandTester $tester;

       protected function setUp(): void
       {
           // Boot a minimal console application wired with BimaajiServiceProvider.
           // Preferred pattern from PhaseN/AgentRuntime tests — adjust to actual kernel helper.
           $kernel    = $this->buildTestKernel();
           $command   = $kernel->getContainer()->get(/* graph:dump command class */);
           $this->tester = new CommandTester($command);
       }

       #[Test]
       public function dumpsFullGraph(): void // FR-011
       {
           $this->tester->execute([]);
           self::assertSame(0, $this->tester->getStatusCode());

           $output = $this->tester->getDisplay();
           $data   = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

           $sectionKeys = array_keys($data['sections'] ?? $data);
           sort($sectionKeys);
           self::assertContains('entities',       $sectionKeys);
           self::assertContains('routing',        $sectionKeys);
           self::assertContains('jsonapi',        $sectionKeys);
           self::assertContains('admin',          $sectionKeys);
           self::assertContains('sovereignty',    $sectionKeys);
           self::assertContains('public_surface', $sectionKeys);
       }

       #[Test]
       public function scopesToSection(): void // FR-012
       {
           $this->tester->execute(['--section' => 'routing']);
           self::assertSame(0, $this->tester->getStatusCode());

           $data = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
           self::assertArrayHasKey('routing', $data['sections'] ?? $data);
           self::assertArrayNotHasKey('entities', $data['sections'] ?? $data);
       }

       #[Test]
       public function failsOnUnknownSection(): void // FR-013
       {
           $this->tester->execute(['--section' => 'nonexistent']);
           self::assertNotSame(0, $this->tester->getStatusCode());

           $output = $this->tester->getDisplay();
           self::assertStringContainsString('Unknown section', $output);
           self::assertStringContainsString('Available sections:', $output);
       }

       private function buildTestKernel(): mixed
       {
           // Use the same minimal kernel pattern as ApplicationGraphIntegrationTest (WP03).
           // Placeholder — fill in after WP03 establishes the helper.
           throw new \LogicException('Replace with actual test kernel helper.');
       }
   }
   ```

2. After WP03 establishes the kernel boot helper, replace `buildTestKernel()` with the
   actual pattern. WP02 and WP03 run in sequence but the test file can be created with a
   `@todo` placeholder for the kernel.

3. Verify the actual `CommandTester` integration pattern by looking at existing integration
   tests:
   ```
   find tests/Integration -name "*Command*Test.php" | head -5
   ```
   Adjust `setUp()` to match the project's existing pattern.

**Files touched:**
- `tests/Integration/PhaseN/Bimaaji/GraphDumpCommandTest.php` — create

**Validation:**
- `./vendor/bin/phpunit tests/Integration/PhaseN/Bimaaji/GraphDumpCommandTest.php`
  — all 3 methods pass.
- `composer phpstan` passes.

---

### T012 — NFR-003 stable output verification

**Purpose:** Confirm that running `graph:dump` twice in the same environment produces
byte-for-byte identical JSON output (NFR-003). Required for M3 (MCP) consumers that may
diff graph snapshots.

**Steps:**

1. Add a stability assertion to `dumpsFullGraph` in `GraphDumpCommandTest`:
   ```php
   // NFR-003: Output must be stable across runs
   $this->tester->execute([]);
   $firstRun  = $this->tester->getDisplay();
   $this->tester->execute([]);
   $secondRun = $this->tester->getDisplay();
   self::assertSame($firstRun, $secondRun, 'graph:dump output must be stable (NFR-003).');
   ```
2. If the assertion fails, identify the source of instability:
   - Hash maps (`array_keys()` without sort): ensure `ksort()` is called in handler.
   - Timestamps or random values in `GraphSection::toArray()`: grep for `time()\|microtime\|rand`:
     ```
     grep -rn "time()\|microtime\|uniqid\|rand" packages/bimaaji/src/
     ```
   - Fix in `GraphDumpHandler` (sort) or file a follow-up if the instability is inside
     an existing provider (C-003 prohibits changing providers).
3. Confirm `JSON_PRETTY_PRINT` is used (required for human-readable output in `--format=json`).

**Files touched:**
- `tests/Integration/PhaseN/Bimaaji/GraphDumpCommandTest.php` — edit (add stability assertion)
- `packages/bimaaji/src/Command/GraphDumpHandler.php` — conditional edit (add `ksort()` if needed)

**Validation:**
- `dumpsFullGraph` passes including the stability assertion.
- No `time()`, `microtime()`, or `uniqid()` calls in the serialisation path.

---

## Test strategy

**Integration tests** (`tests/Integration/PhaseN/Bimaaji/GraphDumpCommandTest.php`):
- `dumpsFullGraph` (FR-011): full dump, parse JSON, assert 6 section keys present.
- `scopesToSection` (FR-012): `--section=routing`, assert only `routing` key present.
- `failsOnUnknownSection` (FR-013): `--section=nonexistent`, non-zero exit, error message
  contains "Available sections:".
- Stability assertion in `dumpsFullGraph` (NFR-003): two runs produce identical output.

**Static analysis:**
- `composer phpstan` passes on `GraphDumpHandler` and `BimaajiServiceProvider` edits.
- No new dead-code findings.

## Definition of Done

- [ ] `packages/bimaaji/src/Command/GraphDumpHandler.php` exists and implements the
  `--section`, `--format`, and `--strict` flags (FR-005).
- [ ] `BimaajiServiceProvider::nativeCommands()` returns a `CommandDefinition` for `graph:dump`
  (FR-005 / C-002).
- [ ] `bin/waaseyaa list` includes `graph:dump` after `optimize:manifest` (FR-005).
- [ ] `graph:dump` exits 0 and prints valid JSON with 6 section keys (FR-006, FR-011).
- [ ] `graph:dump --section=routing` exits 0 with only the `routing` section (FR-012).
- [ ] `graph:dump --section=nonexistent` exits 1 with "Available sections:" message (FR-006, FR-013).
- [ ] Two runs of `graph:dump` produce identical output (NFR-003).
- [ ] `--strict` mode exit code and error message include provider FQCN (NFR-004).
- [ ] Command lives in `packages/bimaaji/src/Command/` (C-002).
- [ ] `composer verify` exits 0 (C-006).
- [ ] `bin/check-package-layers` exits 0 (C-001 / C-002).

## Risks and notes

- **R3 (symfony/yaml):** T008 resolves this before any handler code is written. If YAML
  is demoted to a follow-up, remove the `--format=yaml` option from `CommandDefinition`
  (not just the handler) to avoid confusing consumers.
- **NFR-004 (fail-closed `--strict`):** The error message must name the provider FQCN.
  If `ApplicationGraphGenerator` catches exceptions internally, ensure `--strict` mode
  re-throws or surfaces the FQCN. Grep the existing `generate()` implementation for
  try/catch patterns before writing the handler.
- **C-003 (no provider changes):** If `ApplicationGraph::toArray()` does not sort section
  keys, add `ksort()` in the handler — do NOT modify `ApplicationGraph` or any provider.
- **Handler vs. inline closure:** Plan AD-03 mentions "thin handler class or inline" — the
  extracted `GraphDumpHandler` class is the correct choice for testability and dead-code
  gate compliance (class is bound as a singleton, so it is reachable).

## Reviewer guidance

The opus reviewer should check:

1. **Exit codes** — 0 on success, 1 on unknown section, 1 on provider error in `--strict`
   mode. No other exit codes. Verify against FR-006.
2. **Error message format** — `failsOnUnknownSection` must assert that the error message
   contains *both* `"Unknown section"` and `"Available sections:"`. Partial messages are a
   UX regression.
3. **NFR-004 fail-closed** — Run `graph:dump --strict` with a mock provider that throws,
   and confirm the output names the provider FQCN and error. This is not tested by
   `GraphDumpCommandTest` (which uses real providers that succeed) — the reviewer should
   check that the handler code path for exceptions is reachable.
4. **Stable output** — Confirm `ksort()` is applied before `json_encode()`. The stability
   assertion in T012 catches runtime instability, but the reviewer should also check for
   non-deterministic data sources in the serialisation path.
5. **`symfony/yaml` decision** — If YAML was demoted to follow-up, confirm the option is
   absent from `CommandDefinition` (not just the handler switch).
