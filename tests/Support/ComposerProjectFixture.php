<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

final class ComposerProjectFixture
{
    /**
     * Make a temporary project root look like a Composer-installed Waaseyaa app.
     *
     * The kernel's manifest compiler intentionally reads package discovery
     * metadata from the application project root. Integration tests that boot a
     * temp app therefore need the same vendor/composer contract as a real
     * install, without relying on symlinks or platform-specific filesystem
     * privileges.
     */
    public static function installMetadata(string $repoRoot, string $projectRoot): void
    {
        $vendorComposerSrc = $repoRoot . '/vendor/composer';
        $vendorComposerDst = $projectRoot . '/vendor/composer';

        if (!is_dir($vendorComposerSrc)) {
            throw new \RuntimeException(sprintf('Composer metadata directory does not exist: %s', $vendorComposerSrc));
        }

        if (!is_dir($vendorComposerDst) && !mkdir($vendorComposerDst, 0o755, true)) {
            throw new \RuntimeException(sprintf('Failed to create Composer metadata directory: %s', $vendorComposerDst));
        }

        foreach ([
            'installed.json',
            'installed.php',
            'autoload_psr4.php',
            'autoload_classmap.php',
            'autoload_files.php',
            'autoload_namespaces.php',
        ] as $file) {
            $source = $vendorComposerSrc . '/' . $file;
            if (is_file($source) && !copy($source, $vendorComposerDst . '/' . $file)) {
                throw new \RuntimeException(sprintf('Failed to copy Composer metadata file: %s', $source));
            }
        }

        self::writeAutoloadWrapper($repoRoot, $projectRoot);
    }

    private static function writeAutoloadWrapper(string $repoRoot, string $projectRoot): void
    {
        $autoloadPath = $projectRoot . '/vendor/autoload.php';
        $repoAutoload = $repoRoot . '/vendor/autoload.php';

        if (!is_file($repoAutoload)) {
            throw new \RuntimeException(sprintf('Composer autoloader does not exist: %s', $repoAutoload));
        }

        $content = '<?php' . "\n\n"
            . 'declare(strict_types=1);' . "\n\n"
            . 'return require ' . var_export($repoAutoload, true) . ';' . "\n";

        if (file_put_contents($autoloadPath, $content) === false) {
            throw new \RuntimeException(sprintf('Failed to write Composer autoload wrapper: %s', $autoloadPath));
        }
    }
}
