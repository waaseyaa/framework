<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Waaseyaa\CLI\Site\Exception\DevelopmentInterruption;

/**
 * The development-only interruption seam for `site:apply` (#2789 phase 3).
 *
 * Crash recovery is a promise the execution authority makes about a *later
 * process*: an interrupted publication leaves a durable journal, and the next
 * apply recovers it before doing its own work. Proving that end to end needs a
 * real process that really stops mid-transaction — something no in-process test
 * double can demonstrate about a shipped command.
 *
 * This seam is the smallest thing that makes that provable, and it is
 * deliberately not a fault-injection API:
 *
 * - It takes **no** stage, path, index or count from the operator. It fires
 *   once, at the first target replacement, which is after the transaction
 *   journal is durable and before publication completes.
 * - It exists only when `APP_ENV` is exactly `development`. That is narrower
 *   than {@see \Waaseyaa\Foundation\Kernel\RuntimePolicy::isDevelopmentEnvironment()},
 *   which also admits `dev`, `local` and `testing`: those are environments
 *   people work in, and abandoning a transaction mid-flight must not be one
 *   keystroke away there.
 * - The option is not registered on the command at all outside that
 *   environment, and the handler re-reads the environment before constructing
 *   the injector, so a stale or hand-built command definition cannot enable it.
 * - It bypasses nothing. The lock, the reviewed plan digest, the project-state
 *   digest, path containment and every collision check run first and refuse
 *   first; the seam can only stop work that was already permitted.
 *
 * What it leaves behind is authentic, not fabricated: the journal, stage and
 * backup trees are the ones the real transaction wrote, and the existing
 * recovery authority — not this class — is what interprets them.
 *
 * @api
 */
final class DevelopmentInterruptionSeam
{
    /** The narrowly named `site:apply` option that arms the seam. */
    public const string OPTION = 'interrupt-after-journal';

    /** The one `APP_ENV` value that makes the seam exist. */
    public const string ENVIRONMENT = 'development';

    /**
     * The interrupted-run exit code, distinct from success and from an ordinary
     * failure so a harness can tell what happened.
     *
     * It is `130` — the code the CLI reserves for an interrupted run — and not
     * a code of this seam's own choosing, because
     * {@see \Waaseyaa\CLI\WaaseyaaConsoleApplication::normalizeExitCode()}
     * deliberately collapses every other non-zero handler result to `1`. A
     * bespoke code would therefore be invisible to the very black-box harness
     * this seam exists for, and widening that normalization to carry one would
     * change the exit-code contract of every command in the CLI to serve a
     * development-only seam.
     */
    public const int EXIT_CODE = 130;

    /**
     * The publication stage the seam stops at: the journal is durable, item 0
     * is recorded as installing, and its target has just been replaced.
     */
    private const string STAGE = 'after-replace';

    /**
     * True only for an explicit, exact `APP_ENV=development` process
     * environment. Anything else — unset, empty, `dev`, `local`, `testing`,
     * `production`, a different case — is not development for this purpose.
     */
    public static function isPermitted(): bool
    {
        $environment = getenv('APP_ENV');

        return is_string($environment) && trim($environment) === self::ENVIRONMENT;
    }

    /**
     * The one-shot injector handed to the execution authority. It is a closure
     * over private state, so nothing it is passed to can re-arm or re-aim it.
     *
     * @return \Closure(string, int, string): void
     */
    public static function injector(): \Closure
    {
        if (!self::isPermitted()) {
            throw new \LogicException('The development interruption seam is unavailable outside APP_ENV=' . self::ENVIRONMENT . '.');
        }
        $fired = false;

        return static function (string $stage, int $index, string $path) use (&$fired): void {
            if ($fired || $stage !== self::STAGE) {
                return;
            }
            $fired = true;

            throw new DevelopmentInterruption(sprintf(
                'Interrupted after the transaction journal was durable, while publishing %s (APP_ENV=%s seam).',
                $path,
                self::ENVIRONMENT,
            ));
        };
    }
}
