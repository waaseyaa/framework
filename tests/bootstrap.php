<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: Composer autoload plus the #2927 temp-directory guard.
 *
 * The guard refuses to start the suite when sys_get_temp_dir() is relative or
 * IS the repository root, because every `sys_get_temp_dir() . '/waaseyaa_*_'`
 * scratch helper would then write into the checkout and a killed run would
 * leave empty directories `git status` never reports. See
 * tests/Support/TempDirGuard.php and docs/specs/governed-gates.md §7.
 */

use Waaseyaa\Tests\Support\TempDirGuard;

require __DIR__ . '/../vendor/autoload.php';

$tempDirViolation = TempDirGuard::violation(sys_get_temp_dir(), dirname(__DIR__));
if ($tempDirViolation !== null) {
    fwrite(STDERR, "PHPUnit bootstrap refused (TMPDIR guard): {$tempDirViolation}\n");
    exit(1);
}
unset($tempDirViolation);
