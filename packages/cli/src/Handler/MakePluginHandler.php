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
        try {
            $this->validateIdentifier($name, 'name');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }
        $className = $this->toPascalCase($name);
        $pluginId = strtolower($name);
        try {
            $this->validateMachineName($pluginId, 'plugin id');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }
        // $className is already alnum+underscore-only (validated $name plus
        // the defensive strip in toPascalCase()), but escape it anyway before
        // it lands in a single-quoted attribute literal — belt and suspenders
        // per the ExtensionScaffoldHandler escape-at-the-sink pattern.
        $safeLabel = addslashes($className);

        $template = <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Plugin;

            use Waaseyaa\Plugin\Attribute\WaaseyaaPlugin;

            #[WaaseyaaPlugin(id: '{$pluginId}', label: '{$safeLabel}')]
            class {$className}
            {
            }

            PHP;

        $io->write($template);

        return 0;
    }
}
