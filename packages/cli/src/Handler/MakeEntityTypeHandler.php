<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Make\AbstractMakeHandler;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class MakeEntityTypeHandler extends AbstractMakeHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $name = (string) $io->argument('name');
        $isContent = (bool) $io->option('content');

        $className = str_replace('_', '', ucwords($name, '_'));
        $typeId = strtolower($name);
        $label = ucwords(strtr($name, '_', ' '));

        $template = $isContent
            ? $this->renderContentTemplate($className, $typeId, $label)
            : $this->renderConfigTemplate($className, $typeId);

        $io->write($template);

        return 0;
    }

    /**
     * Emit an attribute-first content entity scaffold.
     */
    private function renderContentTemplate(string $className, string $typeId, string $label): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Entity;

            use Waaseyaa\\Entity\\Attribute\\ContentEntityKeys;
            use Waaseyaa\\Entity\\Attribute\\ContentEntityType;
            use Waaseyaa\\Entity\\Attribute\\Field;
            use Waaseyaa\\Entity\\ContentEntityBase;

            #[ContentEntityType(id: '{$typeId}', label: '{$label}')]
            #[ContentEntityKeys(label: 'title')]
            final class {$className} extends ContentEntityBase
            {
                #[Field] public string \$title = '';
                // Add additional #[Field] properties as needed.
            }

            // Register in your ServiceProvider:
            //
            //     \$this->entityType(EntityType::fromClass({$className}::class, group: 'content'));

            PHP;
    }

    /**
     * Emit a config entity scaffold.
     */
    private function renderConfigTemplate(string $className, string $typeId): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Entity;

            use Waaseyaa\\Entity\\ConfigEntityBase;

            class {$className} extends ConfigEntityBase
            {
                /**
                 * Widen the constructor so {@see \\Waaseyaa\\Entity\\EntityBase::duplicateInstance()} can reconstruct instances.
                 * Replace the defaults below with your entity type id and key map (must match EntityType registration).
                 */
                public function __construct(
                    array \$values = [],
                    string \$entityTypeId = '',
                    array \$entityKeys = [],
                ) {
                    \$entityTypeId = \$entityTypeId !== '' ? \$entityTypeId : '{$typeId}';
                    \$entityKeys = \$entityKeys !== [] ? \$entityKeys : [
                        'id' => 'id',
                        'label' => 'label',
                    ];

                    parent::__construct(\$values, \$entityTypeId, \$entityKeys);
                }
            }

            PHP;
    }
}
