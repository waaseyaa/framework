<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Make\AbstractMakeHandler;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * Scaffold a usable content type in one command (author-path FR-003):
 *
 *   waaseyaa make:content-type story --fields="title:string,body:text,source_url:string"
 *
 * Generates `App\Entity\{Name}` (a content entity with a published `status`
 * field plus each requested field — `entity_reference:<target>` includes the
 * required target metadata, no constructor spelunking), a dedicated
 * `App\Provider\{Name}ServiceProvider` registering it in the `content` group,
 * and registers that provider in the app's `composer.json`
 * `extra.waaseyaa.providers`. In dev the type is then discovered automatically
 * (no optimize:manifest); `waaseyaa schema:sync` materializes its table.
 *
 * @api
 */
final class MakeContentTypeHandler extends AbstractMakeHandler
{
    /** Field type => [phpType, default literal, explicitType?]. */
    private const array TYPE_MAP = [
        'string' => ['string', "''", false],
        'text' => ['?string', 'null', true],
        'integer' => ['?int', 'null', true],
        'float' => ['?float', 'null', true],
        'boolean' => ['bool', 'false', true],
        'datetime' => ['?int', 'null', true],
        'entity_reference' => ['?int', 'null', true],
    ];

    public function __construct(
        private readonly ?string $projectRoot = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $name = (string) $io->argument('name');
        $fieldsSpec = (string) ($io->option('fields') ?? '');
        $force = (bool) $io->option('force');
        $cwd = getcwd();
        $root = $this->projectRoot ?? ($cwd !== false ? $cwd : '.');

        $className = $this->toPascalCase($name);
        $typeId = strtolower($name);
        $label = ucwords(strtr($name, '_', ' '));

        try {
            $fields = $this->parseFields($fieldsSpec);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        if ($fields === []) {
            $io->error('Provide at least one field, e.g. --fields="title:string,body:text".');

            return 1;
        }

        $labelField = $this->labelField($fields);
        $providerClass = $className . 'ServiceProvider';

        $entityPath = $root . '/src/Entity/' . $className . '.php';
        $providerPath = $root . '/src/Provider/' . $providerClass . '.php';

        if (!$force) {
            foreach ([$entityPath, $providerPath] as $existing) {
                if (file_exists($existing)) {
                    $io->error(sprintf('%s already exists (use --force to overwrite).', $existing));

                    return 1;
                }
            }
        }

        try {
            $this->writeFile($entityPath, $this->renderEntity($className, $typeId, $label, $labelField, $fields));
            $this->writeFile($providerPath, $this->renderProvider($providerClass, $className));
            $registered = $this->registerProvider($root . '/composer.json', 'App\\Provider\\' . $providerClass);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $io->writeln(sprintf('Created entity:   %s', $entityPath));
        $io->writeln(sprintf('Created provider: %s', $providerPath));
        $io->writeln($registered
            ? 'Registered provider in composer.json (extra.waaseyaa.providers).'
            : 'Provider already registered in composer.json.');
        $io->writeln('');
        $io->writeln(sprintf('Next: run "waaseyaa schema:sync" to create the %s table, then create content with:', $typeId));
        $io->writeln(sprintf('  waaseyaa entity:create %s --field %s="…" --field status=1', $typeId, $labelField));

        return 0;
    }

    /**
     * @return list<array{name: string, type: string, target: ?string}>
     */
    private function parseFields(string $spec): array
    {
        $fields = [];
        foreach (explode(',', $spec) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $parts = explode(':', $raw);
            $fieldName = trim($parts[0]);
            $type = isset($parts[1]) ? trim($parts[1]) : 'string';
            $target = isset($parts[2]) ? trim($parts[2]) : null;

            if ($fieldName === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $fieldName)) {
                throw new \RuntimeException(sprintf('Invalid field name "%s" (use snake_case).', $fieldName));
            }
            if ($fieldName === 'status') {
                throw new \RuntimeException('"status" is reserved (added automatically as the published flag).');
            }
            if (!isset(self::TYPE_MAP[$type])) {
                throw new \RuntimeException(sprintf('Unknown field type "%s" for "%s". Allowed: %s.', $type, $fieldName, implode(', ', array_keys(self::TYPE_MAP))));
            }
            if ($type === 'entity_reference' && ($target === null || $target === '')) {
                throw new \RuntimeException(sprintf('entity_reference field "%s" needs a target: %s:entity_reference:<target_type>.', $fieldName, $fieldName));
            }

            $fields[] = ['name' => $fieldName, 'type' => $type, 'target' => $target];
        }

        return $fields;
    }

    /**
     * @param list<array{name: string, type: string, target: ?string}> $fields
     */
    private function labelField(array $fields): string
    {
        foreach ($fields as $field) {
            if ($field['type'] === 'string') {
                return $field['name'];
            }
        }

        return $fields[0]['name'];
    }

    /**
     * @param list<array{name: string, type: string, target: ?string}> $fields
     */
    private function renderEntity(string $className, string $typeId, string $label, string $labelField, array $fields): string
    {
        $lines = [];
        // Published flag first — make published content public-read by default.
        $lines[] = "    #[Field(type: 'boolean', label: 'Published', default: true)]";
        $lines[] = '    public bool $status = true;';
        $lines[] = '';

        foreach ($fields as $field) {
            [$phpType, $default] = self::TYPE_MAP[$field['type']];
            $fieldLabel = ucwords(strtr($field['name'], '_', ' '));
            $attrArgs = "type: '{$field['type']}', label: '{$fieldLabel}'";
            if ($field['type'] === 'entity_reference') {
                $attrArgs .= ", settings: ['target_entity_type_id' => '{$field['target']}']";
            }
            $lines[] = "    #[Field({$attrArgs})]";
            $lines[] = "    public {$phpType} \${$field['name']} = {$default};";
            $lines[] = '';
        }
        $fieldBlock = rtrim(implode("\n", $lines));

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Entity;

            use Waaseyaa\\Entity\\Attribute\\ContentEntityKeys;
            use Waaseyaa\\Entity\\Attribute\\ContentEntityType;
            use Waaseyaa\\Entity\\Attribute\\Field;
            use Waaseyaa\\Entity\\ContentEntityBase;

            #[ContentEntityType(id: '{$typeId}', label: '{$label}')]
            #[ContentEntityKeys(label: '{$labelField}')]
            final class {$className} extends ContentEntityBase
            {
            {$fieldBlock}
            }

            PHP;
    }

    private function renderProvider(string $providerClass, string $className): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Provider;

            use App\\Entity\\{$className};
            use Waaseyaa\\Entity\\EntityType;
            use Waaseyaa\\Foundation\\ServiceProvider\\ServiceProvider;

            final class {$providerClass} extends ServiceProvider
            {
                public function register(): void
                {
                    \$this->entityType(EntityType::fromClass({$className}::class, group: 'content'));
                }
            }

            PHP;
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create directory: %s', $dir));
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(sprintf('Could not write: %s', $path));
        }
    }

    /**
     * Add the provider FQCN to the app composer.json `extra.waaseyaa.providers`.
     * Returns true if added, false if it was already present.
     */
    private function registerProvider(string $composerPath, string $fqcn): bool
    {
        if (!is_file($composerPath)) {
            throw new \RuntimeException(sprintf('composer.json not found at %s — cannot register the provider.', $composerPath));
        }

        $raw = (string) file_get_contents($composerPath);
        try {
            /** @var array<string, mixed> $composer */
            $composer = json_decode($raw, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('composer.json is not valid JSON: %s', $e->getMessage()));
        }

        $extra = \is_array($composer['extra'] ?? null) ? $composer['extra'] : [];
        $waaseyaa = \is_array($extra['waaseyaa'] ?? null) ? $extra['waaseyaa'] : [];
        $providers = \is_array($waaseyaa['providers'] ?? null) ? array_values($waaseyaa['providers']) : [];

        if (\in_array($fqcn, $providers, true)) {
            return false;
        }

        $providers[] = $fqcn;
        $waaseyaa['providers'] = $providers;
        $extra['waaseyaa'] = $waaseyaa;
        $composer['extra'] = $extra;

        $encoded = json_encode($composer, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        if (file_put_contents($composerPath, $encoded . "\n") === false) {
            throw new \RuntimeException(sprintf('Could not update composer.json at %s', $composerPath));
        }

        return true;
    }
}
