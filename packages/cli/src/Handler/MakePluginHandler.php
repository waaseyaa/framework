<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Make\AbstractMakeHandler;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class MakePluginHandler extends AbstractMakeHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $name = (string) $io->argument('name');
        $className = str_replace('_', '', ucwords($name, '_'));
        $pluginId = strtolower($name);

        $template = <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Plugin;

            use Waaseyaa\Plugin\Attribute\WaaseyaaPlugin;

            #[WaaseyaaPlugin(id: '{$pluginId}', label: '{$className}')]
            class {$className}
            {
            }

            PHP;

        $io->write($template);

        return 0;
    }
}
