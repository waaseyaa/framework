<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class ExtensionScaffoldHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $id = strtolower(trim((string) ($io->option('id') ?? '')));
        $label = trim((string) ($io->option('label') ?? ''));
        $packageRaw = trim((string) ($io->option('package') ?? ''));
        $class = trim((string) ($io->option('class') ?? 'KnowledgeExtension'));
        $description = trim((string) ($io->option('description') ?? 'External knowledge tooling extension'));
        $workflowTag = trim((string) ($io->option('workflow-tag') ?? 'external-extension'));
        $relationshipType = strtolower(trim((string) ($io->option('relationship-type') ?? 'related')));
        $discoveryHint = trim((string) ($io->option('discovery-hint') ?? 'external-discovery-hint'));

        if ($id === '' || $label === '' || $packageRaw === '' || $class === '' || $description === '') {
            $io->error('--id, --label, --package, --class, and --description are required.');
            return 2;
        }
        if (!$this->isValidPluginId($id)) {
            $io->error('--id must match: [a-z][a-z0-9_]*.');
            return 2;
        }
        if (!$this->isValidPackageName($packageRaw)) {
            $io->error('--package must match composer format: vendor/package (lowercase).');
            return 2;
        }
        if (!$this->isValidClassName($class)) {
            $io->error('--class must be a valid PascalCase PHP class name.');
            return 2;
        }
        if ($workflowTag === '' || $relationshipType === '' || $discoveryHint === '') {
            $io->error('--workflow-tag, --relationship-type, and --discovery-hint are required.');
            return 2;
        }

        $package = strtolower($packageRaw);
        $namespace = trim((string) ($io->option('namespace') ?? ''));
        if ($namespace === '') {
            $namespace = $this->deriveNamespaceFromPackage($package);
        }
        if (!$this->isValidNamespace($namespace)) {
            $io->error('--namespace must be a valid PHP namespace.');
            return 2;
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

        $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return 0;
    }

    // The `D` modifier on each allowlist below anchors `$` to the true end of
    // string; without it PHP's `$` matches before a trailing `\n`, so a payload
    // with a trailing newline would slip past the anchor and land (unescaped
    // for the class/namespace) in the generated PHP template. These stay
    // ASCII-only by design — extension package/class/namespace names are
    // developer-facing SDK infrastructure, not Indigenous-orthography content.

    private function isValidPluginId(string $id): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]*$/D', $id);
    }

    private function isValidPackageName(string $package): bool
    {
        return (bool) preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/D', $package);
    }

    private function isValidClassName(string $class): bool
    {
        return (bool) preg_match('/^[A-Z][A-Za-z0-9]*$/D', $class);
    }

    private function isValidNamespace(string $namespace): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace);
    }

    private function deriveNamespaceFromPackage(string $package): string
    {
        $segments = preg_split('/[\/._-]+/', $package);
        $segments = $segments !== false ? $segments : [];
        $parts = [];
        foreach ($segments as $segment) {
            $normalized = trim($segment);
            if ($normalized === '') {
                continue;
            }
            $parts[] = str_replace(' ', '', ucwords($normalized));
        }

        return implode('\\', $parts);
    }

    /**
     * @param array{interface: string, runner: string, surfaces: list<string>} $contracts
     */
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
        ]);
    }
}
