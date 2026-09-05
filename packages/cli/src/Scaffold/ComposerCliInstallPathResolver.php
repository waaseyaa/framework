<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Scaffold;

use Composer\InstalledVersions;

/**
 * Default {@see CliInstallPathResolverInterface}: asks Composer's installed-
 * package metadata for the waaseyaa/cli install path (candidate (c) in
 * {@see AuthUiScaffoldManager::sourceCandidates()}).
 */
final class ComposerCliInstallPathResolver implements CliInstallPathResolverInterface
{
    public function resolve(): ?string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('waaseyaa/cli')) {
            return null;
        }

        $installedPath = InstalledVersions::getInstallPath('waaseyaa/cli');
        if (!is_string($installedPath) || $installedPath === '') {
            return null;
        }

        $resolved = realpath($installedPath);

        return $resolved === false ? $installedPath : $resolved;
    }
}
