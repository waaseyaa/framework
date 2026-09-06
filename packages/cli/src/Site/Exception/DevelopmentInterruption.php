<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Exception;

/**
 * The abandonment signal raised by the development-only interruption seam
 * (#2789 phase 3).
 *
 * It extends `\Error`, not `\Exception`, and that is the whole point: the
 * publication path catches `\Exception` to roll a failed transaction back, and
 * a simulated process death must *not* be rolled back — the interrupted
 * journal is exactly the state the next process has to recover. An `\Error`
 * passes through those handlers untouched while `finally` still releases the
 * project lock, which is what an abrupt exit would also leave behind.
 *
 * It is never thrown outside {@see \Waaseyaa\CLI\Site\DevelopmentInterruptionSeam},
 * which cannot be constructed outside an explicit development environment.
 *
 * @api
 */
final class DevelopmentInterruption extends \Error {}
