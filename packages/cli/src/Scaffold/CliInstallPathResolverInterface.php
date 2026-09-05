<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Scaffold;

/**
 * Resolves the installed root directory of the waaseyaa/cli package.
 *
 * Extracted so unit tests can drive AuthUiScaffoldManager's package-owned
 * candidate (c) — hit, miss, missing directory, and corrupt resource
 * branches — without touching the real vendor/ install of this repository.
 * A prior review established that Composer\InstalledVersions::reload() cannot
 * override this repository's own registered vendor dir from inside its own
 * test suite, so tests substitute an implementation of this interface
 * instead (#2833 repair design, decision 3).
 */
interface CliInstallPathResolverInterface
{
    /**
     * @return string|null the installed waaseyaa/cli package root, or null
     *     when Composer has no record of the package being installed
     */
    public function resolve(): ?string;
}
