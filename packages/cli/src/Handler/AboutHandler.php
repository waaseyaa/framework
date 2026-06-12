<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\CliIO;
use Waaseyaa\Foundation\Kernel\Bootstrap\DatabaseBootstrapper;
use Waaseyaa\Foundation\Kernel\ConfigLoader;

final class AboutHandler
{
    /**
     * @param array<string, string> $info Key-value pairs of system information.
     * @param string|null $projectRoot Project root for database path
     *        resolution; when null, falls back to the process CWD —
     *        `bin/waaseyaa` validates the CWD is the project root before
     *        dispatching, so the fallback matches the kernel's own root in
     *        CLI context.
     */
    public function __construct(
        private readonly array $info = [],
        private readonly ?string $projectRoot = null,
    ) {}

    public function execute(CliIO $io): int
    {
        $info = array_merge($this->getDefaultInfo(), $this->info);

        $io->writeln('Waaseyaa');
        $io->writeln('');

        $maxKey = max(array_map('strlen', array_keys($info)));

        foreach ($info as $key => $value) {
            $io->writeln(sprintf('  %-' . $maxKey . 's  %s', $key, $value));
        }

        return 0;
    }

    /**
     * @return array<string, string>
     */
    private function getDefaultInfo(): array
    {
        return [
            'Waaseyaa Version' => '0.1.0',
            'PHP Version' => PHP_VERSION,
            'Environment' => $_ENV['APP_ENV'] ?? 'production',
            'Debug Mode' => ($_ENV['APP_DEBUG'] ?? '0') === '1' ? 'ON' : 'OFF',
            'Database' => $this->resolvedDatabasePath(),
            'Config Dir' => $_ENV['WAASEYAA_CONFIG_DIR'] ?? './config/sync',
            'OS' => PHP_OS_FAMILY,
        ];
    }

    /**
     * Resolved through the kernel's canonical resolver (#1650 / FR-007) so
     * operators see the path the kernel actually opens, not the raw env
     * value.
     */
    private function resolvedDatabasePath(): string
    {
        $cwd = getcwd();
        $projectRoot = $this->projectRoot ?? ($cwd !== false ? $cwd : '.');

        return DatabaseBootstrapper::resolveDatabasePath(
            $projectRoot,
            ConfigLoader::load($projectRoot . '/config/waaseyaa.php'),
        );
    }
}
