<?php

// Bootstrap for running lane-worktree tests from the main vendor autoloader.
// Registers the worktree's new package sources so PSR-4 resolution finds them.

$loader = require '/home/fsd42/dev/waaseyaa/vendor/autoload.php';

$worktreeRoot = __DIR__;

// New WP03 sources — packages that have been modified/added in this lane.
$loader->addPsr4('Waaseyaa\\Api\\', $worktreeRoot . '/packages/api/src/');
$loader->addPsr4('Waaseyaa\\Api\\Tests\\', $worktreeRoot . '/packages/api/tests/');
$loader->addPsr4('Waaseyaa\\Foundation\\', $worktreeRoot . '/packages/foundation/src/');
$loader->addPsr4('Waaseyaa\\Media\\', $worktreeRoot . '/packages/media/src/');
$loader->addPsr4('Waaseyaa\\Tests\\', $worktreeRoot . '/tests/');
