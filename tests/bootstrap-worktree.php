<?php

/**
 * Worktree test bootstrap.
 *
 * Loads the main repo vendor autoloader and then registers worktree-specific
 * PSR-4 paths so PHPUnit can discover new classes in this lane worktree
 * without requiring a separate `composer install`.
 *
 * This file is used by phpunit.xml (worktree override) but NOT committed to
 * phpunit.xml.dist (which is shared with main). It is gitignored from the
 * lane worktree perspective.
 */

declare(strict_types=1);

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    // Fallback to main repo vendor if symlink not set up
    $vendorAutoload = '/home/fsd42/dev/waaseyaa/vendor/autoload.php';
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $vendorAutoload;

// Override worktree package src paths so worktree versions take precedence
// over the main repo versions already registered by vendor/autoload.php.
// setPsr4() replaces the existing mapping rather than appending to it.
$worktreeRoot = dirname(__DIR__);

$loader->setPsr4('Waaseyaa\\Field\\', [$worktreeRoot . '/packages/field/src']);
$loader->setPsr4('Waaseyaa\\Field\\Tests\\', [$worktreeRoot . '/packages/field/tests']);
$loader->setPsr4('Waaseyaa\\Access\\', [$worktreeRoot . '/packages/access/src']);
$loader->setPsr4('Waaseyaa\\Access\\Tests\\', [$worktreeRoot . '/packages/access/tests']);
$loader->setPsr4('Waaseyaa\\Media\\', [$worktreeRoot . '/packages/media/src']);
$loader->setPsr4('Waaseyaa\\Media\\Tests\\', [$worktreeRoot . '/packages/media/tests']);
$loader->setPsr4('Waaseyaa\\Attachment\\', [$worktreeRoot . '/packages/attachment/src']);
$loader->setPsr4('Waaseyaa\\Attachment\\Tests\\', [$worktreeRoot . '/packages/attachment/tests']);
$loader->addPsr4('Waaseyaa\\Tests\\Integration\\PhasePerRecordAiAccess\\', [$worktreeRoot . '/tests/Integration/PhasePerRecordAiAccess']);
