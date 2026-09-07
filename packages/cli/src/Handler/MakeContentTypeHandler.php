<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Make\AbstractMakeHandler;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationExecutionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationLockedException;
use Waaseyaa\CLI\Site\Scaffold\ContentTypeScaffoldCompiler;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\Field\FieldScaffoldProjection;
use Waaseyaa\Field\FieldValueKind;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;

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
 * This handler validates input and reports; it writes nothing (#2789 phase 2).
 * {@see ContentTypeScaffoldCompiler} turns the validated input into one
 * immutable `ArtifactPlan`, and {@see SiteInitializationService} owns path
 * containment, collision refusal, the provider merge, the durable journal,
 * rollback, receipts and the two state digests. Publication therefore requires
 * an initialized site: ownership of a non-root generation unit is recorded in
 * `.waaseyaa/generated.json`, and there is no roster to record it in before
 * `site:init` has run.
 *
 * @api
 */
final class MakeContentTypeHandler extends AbstractMakeHandler
{
    public function __construct(
        private readonly FieldScaffoldProjection $fieldProjection,
        private readonly ?string $projectRoot = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $name = (string) $io->argument('name');
        $fieldsSpec = (string) ($io->option('fields') ?? '');
        $force = (bool) $io->option('force');
        $cwd = getcwd();
        $root = $this->projectRoot ?? ($cwd !== false ? $cwd : '.');

        try {
            $this->validateIdentifier($name, 'name');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $className = $this->toPascalCase($name);
        $typeId = strtolower($name);
        try {
            $this->validateMachineName($typeId, 'entity type id');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }
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

        $compiler = new ContentTypeScaffoldCompiler($this->fieldProjection);
        $labelField = $compiler->labelField($fields);
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
            $plan = $compiler->compile($name, $className, $fields);
            // The single-invocation flow of ADR-025 D-6.5: compile, evaluate and
            // apply happen once, in one process, through the same two-digest
            // gate a transported plan passes. There is one publication engine.
            $result = new SiteInitializationService($root)->initialize($plan);
        } catch (
            GenerationRefusalException
            | SiteInitializationCollisionException
            | SiteInitializationExecutionException
            | SiteInitializationLockedException
            | \InvalidArgumentException
            | \RuntimeException $e
        ) {
            $io->error($e->getMessage());

            return 1;
        }

        if ($result->changedPaths === []) {
            // A seeded unit is published once and is then the developer's:
            // re-running cannot overwrite the edits that are the point of a
            // scaffold, with or without --force.
            $io->writeln(sprintf('Unchanged: %s is already published and is owned by you.', $plan->unitId));
        } else {
            $io->writeln(sprintf('Created entity:   %s', $entityPath));
            $io->writeln(sprintf('Created provider: %s', $providerPath));
            $io->writeln(in_array('composer.json', $result->changedPaths, true)
                ? 'Registered provider in composer.json (extra.waaseyaa.providers).'
                : 'Provider already registered in composer.json.');
        }
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
        $fieldTypeIds = $this->fieldProjection->fieldTypeIds();
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
            if (!in_array($type, $fieldTypeIds, true)) {
                throw new \RuntimeException(sprintf(
                    'Unknown field type "%s" for "%s", or its registered metadata is incomplete for scaffolding. Registered scaffold types: %s.',
                    $type,
                    $fieldName,
                    implode(', ', $fieldTypeIds),
                ));
            }
            if ($this->fieldProjection->valueKind($type) === FieldValueKind::EntityReference) {
                if ($target === null || $target === '') {
                    throw new \RuntimeException(sprintf('entity_reference field "%s" needs a target: %s:entity_reference:<target_type>.', $fieldName, $fieldName));
                }
                // $target is interpolated raw into a generated
                // `settings: ['target_entity_type_id' => '...']` PHP attribute
                // literal by the compiler — it needs a machine-name allowlist,
                // or a quote here breaks out of that literal. Unicode-aware (a reference
                // target may be an Indigenous-orthography entity-type id created
                // by make:entity-type); the `u`+`D` flags keep it injection-safe
                // (no quote/backslash/newline/`.`/`/` in `\p{L}\p{N}_`).
                if (!preg_match(self::MACHINE_NAME_PATTERN, $target)) {
                    throw new \RuntimeException(sprintf('Invalid entity_reference target "%s" for "%s".', $target, $fieldName));
                }
            }

            $this->fieldProjection->property(
                $this->fieldProjection->definition($fieldName, $type, $target),
            );
            $fields[] = ['name' => $fieldName, 'type' => $type, 'target' => $target];
        }

        return $fields;
    }
}
