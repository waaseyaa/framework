<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Make\AbstractMakeHandler;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class MakeProviderHandler extends AbstractMakeHandler
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
        $isDomain = (bool) $io->option('domain');

        // Normalize: "Blog" → "BlogServiceProvider", "BlogServiceProvider" → as-is.
        $className = $this->toPascalCase($name);
        if (!str_ends_with($className, 'ServiceProvider')) {
            $className .= 'ServiceProvider';
        }

        // Extract domain name from class: "BlogServiceProvider" → "Blog".
        $domain = str_replace('ServiceProvider', '', $className);

        if ($isDomain) {
            $entityTypeId = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $domain) ?? $domain);
            try {
                $this->validateMachineName($entityTypeId, 'entity type id');
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());

                return 1;
            }
            $rendered = $this->renderStub('provider-domain', [
                'class' => $className,
                'domain' => $domain,
                'entity_namespace' => 'App\\Entity',
                'entity_class' => $domain,
                'entity_type_id' => $entityTypeId,
                'entity_label' => $domain,
            ]);
        } else {
            $rendered = $this->renderStub('provider', [
                'class' => $className,
            ]);
        }

        $io->write($rendered);

        return 0;
    }
}
