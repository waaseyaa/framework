# CLI Tests for schedule:run and schedule:list (#639)

## Context

Issue #639 adds unit tests for the two scheduler CLI commands (`ScheduleRunCommand`, `ScheduleListCommand`). These commands currently have zero test coverage. The existing `QueueRetryCommandTest` establishes the pattern: Symfony `CommandTester`, `#[CoversClass]`, `#[Test]` attributes.

## Approach

Two new test classes in `packages/cli/tests/Unit/Command/`, using `CommandTester` with real/stub dependencies. Follow `QueueRetryCommandTest` conventions exactly.

## Test Files

### `ScheduleListCommandTest`

- **File**: `packages/cli/tests/Unit/Command/ScheduleListCommandTest.php`
- **Covers**: `ScheduleListCommand`
- **Dependency**: `ScheduleInterface` — stub via anonymous class returning configurable `list<ScheduledTask>`

**Tests:**
1. `lists_registered_tasks` — schedule has 2 `ScheduledTask` entries → output contains task names, cron expressions
2. `shows_empty_message_when_no_tasks` — schedule returns `[]` → output contains "No scheduled tasks registered."
3. `returns_success_status_code` — status code is 0

### `ScheduleRunCommandTest`

- **File**: `packages/cli/tests/Unit/Command/ScheduleRunCommandTest.php`
- **Covers**: `ScheduleRunCommand`
- **Dependency**: `ScheduleRunner` — real instance with stub `ScheduleInterface`, stub `QueueInterface`, stub `LockInterface`

**Tests:**
1. `shows_executed_task_names` — schedule has a due task (using `* * * * *` cron) → output lists task name
2. `shows_no_tasks_due_message` — schedule has no tasks → output contains "No scheduled tasks are due."
3. `returns_success_status_code` — status code is 0

### Test Doubles

All inline anonymous classes (no shared fixtures needed — these are simple no-op stubs):
- `ScheduleInterface`: `tasks()` returns configurable array, `add()`/`job()`/`call()` throw `BadMethodCallException`
- `QueueInterface`: `push()` is no-op (captures dispatched job class names)
- `LockInterface`: `acquire()` returns true, `release()` is no-op

## Verification

1. `./vendor/bin/phpunit packages/cli/tests/Unit/Command/ScheduleListCommandTest.php` — all tests pass
2. `./vendor/bin/phpunit packages/cli/tests/Unit/Command/ScheduleRunCommandTest.php` — all tests pass
3. `composer cs-check` — no violations
4. `composer phpstan` — no new errors
