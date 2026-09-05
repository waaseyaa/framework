<?php

declare(strict_types=1);

/**
 * Path-based composed view of the public-surface declaration plane.
 *
 *   $map = require '/path/to/monorepo/tools/lib/compose-public-surface.php';
 *   // array<string, string>  FQCN => public|internal|extract|remove
 *
 * Package-local tests use this exactly as they once `require`d
 * docs/public-surface-map.php: by path, without naming a Waaseyaa\Tooling
 * class (bin/check-package-layers PL010 forbids package tests referencing a
 * namespace that is not a PSR-4 package root). Unlike the generated map, which
 * may lag the declarations until the next release cut, this composes the live
 * declarations on every require. Contract: docs/specs/public-surface-declarations.md §3.
 */

require_once __DIR__ . '/SurfaceScanner.php';
require_once __DIR__ . '/SurfaceDeclarations.php';

return \Waaseyaa\Tooling\SurfaceDeclarations::load(dirname(__DIR__, 2))->compose();
