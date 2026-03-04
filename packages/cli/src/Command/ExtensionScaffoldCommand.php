<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scaffold:extension',
    description: 'Generate deterministic external extension SDK scaffold JSON',
)]
final class ExtensionScaffoldCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Plugin ID (machine name)')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Plugin label')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Composer package name (vendor/package)')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Root PHP namespace (auto-derived from package when omitted)')
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'Extension class name', 'KnowledgeExtension')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Plugin description', 'External knowledge tooling extension')
            ->addOption('workflow-tag', null, InputOption::VALUE_REQUIRED, 'Default workflow tag', 'external-extension')
            ->addOption('relationship-type', null, InputOption::VALUE_REQUIRED, 'Default traversal relationship type', 'related')
            ->addOption('discovery-hint', null, InputOption::VALUE_REQUIRED, 'Default discovery hint', 'external-discovery-hint');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = strtolower(trim((string) $input->getOption('id')));
        $label = trim((string) $input->getOption('label'));
        $packageRaw = trim((string) $input->getOption('package'));
        $class = trim((string) $input->getOption('class'));
        $description = trim((string) $input->getOption('description'));
        $workflowTag = trim((string) $input->getOption('workflow-tag'));
        $relationshipType = strtolower(trim((string) $input->getOption('relationship-type')));
        $discoveryHint = trim((string) $input->getOption('discovery-hint'));

        if ($id === '' || $label === '' || $packageRaw === '' || $class === '' || $description === '') {
            $output->writeln('<error>--id, --label, --package, --class, and --description are required.</error>');
            return Command::INVALID;
        }
        if (!$this->isValidPluginId($id)) {
            $output->writeln('<error>--id must match: [a-z][a-z0-9_]*.</error>');
            return Command::INVALID;
        }
        if (!$this->isValidPackageName($packageRaw)) {
            $output->writeln('<error>--package must match composer format: vendor/package (lowercase).</error>');
            return Command::INVALID;
        }
        if (!$this->isValidClassName($class)) {
            $output->writeln('<error>--class must be a valid PascalCase PHP class name.</error>');
            return Command::INVALID;
        }
        if ($workflowTag === '' || $relationshipType === '' || $discoveryHint === '') {
            $output->writeln('<error>--workflow-tag, --relationship-type, and --discovery-hint are required.</error>');
            return Command::INVALID;
        }

        $package = strtolower($packageRaw);
        $namespace = trim((string) $input->getOption('namespace'));
        if ($namespace === '') {
            $namespace = $this->deriveNamespaceFromPackage($package);
        }
        if (!$this->isValidNamespace($namespace)) {
            $output->writeln('<error>--namespace must be a valid PHP namespace.</error>');
            return Command::INVALID;
        }

        $contracts = [
            'interface' => 'Waaseyaa\\Plugin\\Extension\\KnowledgeToolingExtensionInterface',
            'runner' => 'Waaseyaa\\Plugin\\Extension\\KnowledgeToolingExtensionRunner',
            'surfaces' => ['workflow', 'traversal', 'discovery'],
        ];

        $files = [
            'README.md' => $this->buildReadmeTemplate($package, $id, $contracts),
            'composer.json' => $this->buildComposerTemplate($package, $namespace),
            'src/' . $class . '.php' => $this->buildClassTemplate(
                namespace: $namespace,
                class: $class,
                id: $id,
                label: $label,
                description: $description,
                workflowTag: $workflowTag,
                relationshipType: $relationshipType,
                discoveryHint: $discoveryHint,
            ),
        ];
        ksort($files);

        $payload = [
            'extension_sdk' => [
                'plugin' => [
                    'id' => $id,
                    'label' => $label,
                    'description' => $description,
                ],
                'package' => [
                    'name' => $package,
                    'namespace' => $namespace,
                    'class' => $class,
                ],
                'contracts' => $contracts,
                'defaults' => [
                    'workflow_tag' => $workflowTag,
                    'relationship_type' => $relationshipType,
                    'discovery_hint' => $discoveryHint,
                ],
                'files' => $files,
            ],
        ];

        $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    private function isValidPluginId(string $id): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $id);
    }

    private function isValidPackageName(string $package): bool
    {
        return (bool) preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/', $package);
    }

    private function isValidClassName(string $class): bool
    {
        return (bool) preg_match('/^[A-Z][A-Za-z0-9]*$/', $class);
    }

    private function isValidNamespace(string $namespace): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $namespace);
    }

    private function deriveNamespaceFromPackage(string $package): string
    {
        $segments = preg_split('/[\/._-]+/', $package) ?: [];
        $parts = [];
        foreach ($segments as $segment) {
            $normalized = trim((string) $segment);
            if ($normalized === '') {
                continue;
            }
            $parts[] = str_replace(' ', '', ucwords($normalized));
        }

        return implode('\\', $parts);
    }

    private function buildComposerTemplate(string $package, string $namespace): string
    {
        $payload = [
            'name' => $package,
            'description' => 'External Waaseyaa knowledge tooling extension module',
            'type' => 'library',
            'require' => [
                'php' => '^8.3',
                'waaseyaa/plugin' => '@dev',
            ],
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\' => 'src/',
                ],
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function buildReadmeTemplate(string $package, string $pluginId, array $contracts): string
    {
        return implode("\n", [
            '# ' . $package,
            '',
            'External extension scaffold generated by `scaffold:extension`.',
            '',
            '## Plugin ID',
            '',
            '- `' . $pluginId . '`',
            '',
            '## Contract Surfaces',
            '',
            '- Interface: `' . $contracts['interface'] . '`',
            '- Runner: `' . $contracts['runner'] . '`',
            '- Surfaces: `' . implode('`, `', $contracts['surfaces']) . '`',
            '',
            '## Next Steps',
            '',
            '1. Adjust default tags/hints in the generated class.',
            '2. Publish package and wire it into app bootstrap.',
            '3. Verify via MCP and workflow/traversal diagnostics.',
        ]);
    }

    private function buildClassTemplate(
        string $namespace,
        string $class,
        string $id,
        string $label,
        string $description,
        string $workflowTag,
        string $relationshipType,
        string $discoveryHint,
    ): string {
        $attribute = sprintf(
            "#[WaaseyaaPlugin(id: '%s', label: '%s', description: '%s')]",
            $id,
            addslashes($label),
            addslashes($description),
        );

        return implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace ' . $namespace . ';',
            '',
            'use Waaseyaa\\Plugin\\Attribute\\WaaseyaaPlugin;',
            'use Waaseyaa\\Plugin\\Extension\\KnowledgeToolingExtensionInterface;',
            'use Waaseyaa\\Plugin\\PluginBase;',
            '',
            $attribute,
            'final class ' . $class . ' extends PluginBase implements KnowledgeToolingExtensionInterface',
            '{',
            '    public function alterWorkflowContext(array $context): array',
            '    {',
            "        \$context['workflow_tags'] = array_values(array_unique(array_merge(\$context['workflow_tags'] ?? [], ['" . addslashes($workflowTag) . "'])));",
            "        sort(\$context['workflow_tags']);",
            '        return $context;',
            '    }',
            '',
            '    public function alterTraversalContext(array $context): array',
            '    {',
            "        \$context['relationship_types'] = array_values(array_unique(array_merge(\$context['relationship_types'] ?? [], ['" . addslashes($relationshipType) . "'])));",
            "        sort(\$context['relationship_types']);",
            '        return $context;',
            '    }',
            '',
            '    public function alterDiscoveryContext(array $context): array',
            '    {',
            "        \$context['hints'] = array_values(array_unique(array_merge(\$context['hints'] ?? [], ['" . addslashes($discoveryHint) . "'])));",
            "        sort(\$context['hints']);",
            '        return $context;',
            '    }',
            '}',
            '',
        ]);
    }
}
