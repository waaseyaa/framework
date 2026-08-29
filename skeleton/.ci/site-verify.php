#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The portable site-verification entry point.
 *
 * This is the one implementation. `composer site-verify` reaches it through
 * Composer's own PHP, the `.ci/site-verify` shell script execs it, and any
 * hosted runner can invoke it as `php .ci/site-verify.php`. It is plain PHP
 * with no shebang dependency, so it behaves identically on Linux, macOS, and
 * native Windows, where Composer cannot execute a shebang script at all.
 *
 * It deliberately loads no autoloader and boots no kernel. Verification before
 * `site:init` is an ordinary, expected state — not an error — and reporting it
 * must not depend on the framework the project has not initialized yet. Two
 * `is_file()` checks are the whole test.
 *
 * Exit codes:
 *   0  verified
 *   2  dependencies are not installed
 *   3  the project has not been initialized; run site:init
 *   *  whatever the generated verification command returned
 */

$root = dirname(__DIR__);

$instruct = static function (string $reason): never {
    fwrite(STDERR, "site-verify: {$reason}\n\n");
    fwrite(STDERR, "This project has no site contract yet. Initialize it, then install:\n\n");
    fwrite(STDERR, "  php vendor/bin/waaseyaa site:init\n");
    fwrite(STDERR, "  php vendor/bin/waaseyaa install:init\n\n");
    fwrite(STDERR, "Then re-run: composer site-verify\n");
    exit(3);
};

if (!is_file($root . '/.waaseyaa/site.yaml')) {
    $instruct('this project has not been initialized yet.');
}

// site:init generates the verification command. A manifest without it means a
// partial or hand-edited artifact set, and site:init is still the repair.
if (!is_file($root . '/bin/maintenance/site-verify')) {
    $instruct('the generated verification command is missing.');
}

if (!is_file($root . '/vendor/bin/phpunit')) {
    fwrite(STDERR, "site-verify: dependencies are not installed.\n");
    fwrite(STDERR, "Run: composer install\n");
    exit(2);
}

passthru(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/maintenance/site-verify'),
    $exitCode,
);

exit($exitCode);
