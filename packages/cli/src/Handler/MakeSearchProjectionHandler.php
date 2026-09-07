<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Make\AbstractMakeHandler;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationExecutionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationLockedException;
use Waaseyaa\CLI\Site\Scaffold\SearchProjectionScaffoldCompiler;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;

/**
 * Scaffold a usable search projector in one command (#2849):
 *
 *   waaseyaa make:search-projection story --fields="body,summary"
 *
 * Generates `App\Search\{Name}SearchProjector` — an
 * `EntitySearchProjectorInterface` implementation for an entity type the
 * developer already has, indexing the requested existing fields through the
 * guarded field-read accessor — a dedicated
 * `App\Provider\{Name}SearchServiceProvider` registering it as the
 * application's `ProvidesEntitySearchProjectorsInterface`, and registers that
 * provider in the app's `composer.json` `extra.waaseyaa.providers`. The
 * container resolves exactly one `ProvidesEntitySearchProjectorsInterface`,
 * so this handler refuses to scaffold a second one (fail-closed) — an
 * additional projector belongs in the existing provider's
 * `entitySearchProjectors()` list.
 *
 * This handler validates input and reports; it writes nothing (mirrors
 * #2789 phase 2's `make:content-type`). {@see SearchProjectionScaffoldCompiler}
 * turns the validated input into one immutable `ArtifactPlan`, and
 * {@see SiteInitializationService} owns path containment, collision refusal,
 * the provider merge, the durable journal, rollback, receipts and the two
 * state digests. Publication therefore requires an initialized site:
 * ownership of a non-root generation unit is recorded in
 * `.waaseyaa/generated.json`, and there is no roster to record it in before
 * `site:init` has run.
 *
 * @api
 */
final class MakeSearchProjectionHandler extends AbstractMakeHandler
{
    /**
     * Every search symbol the generated projector, provider and test reference.
     * Checked by name, because the point is to survive the absence being detected.
     *
     * @var list<string>
     */
    private const array REQUIRED_SEARCH_SYMBOLS = [
        'Waaseyaa\\Search\\Projection\\EntitySearchProjectorInterface',
        'Waaseyaa\\Search\\ProvidesEntitySearchProjectorsInterface',
        'Waaseyaa\\Search\\Document\\SearchDocument',
        'Waaseyaa\\Search\\Projection\\EntitySearchDocumentId',
        'Waaseyaa\\Search\\Projection\\SearchTextNormalizer',
    ];

    /**
     * @param list<string>|null $requiredSearchSymbols the search surface the generated
     *   code depends on. Defaults to the real contract; injectable so the
     *   refusal path can be proven without uninstalling a package.
     */
    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?array $requiredSearchSymbols = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $entityType = (string) $io->argument('entity-type');
        $fieldsSpec = (string) ($io->option('fields') ?? '');
        $force = (bool) $io->option('force');
        $cwd = getcwd();
        $root = $this->projectRoot ?? ($cwd !== false ? $cwd : '.');

        // Negotiate the optional capability before anything else, so a target
        // without the search extension surface is refused with a stable
        // diagnostic and an unchanged application rather than receiving code
        // that references classes it cannot load. `waaseyaa/cli` currently
        // requires `waaseyaa/search`, so a reachable install always satisfies
        // this; the guard exists so a narrowed dependency or a partial vendor
        // tree fails here, loudly, instead of at the consumer's boot.
        $missing = $this->missingSearchCapability();
        if ($missing !== null) {
            $io->error(sprintf(
                'This project does not provide the search extension surface (%s is unavailable). Require waaseyaa/search, then re-run; nothing has been written.',
                $missing,
            ));

            return 1;
        }

        try {
            $this->validateIdentifier($entityType, 'entity-type');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $entityTypeId = strtolower($entityType);
        try {
            $this->validateMachineName($entityTypeId, 'entity type id');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $className = $this->toPascalCase($entityType);

        try {
            $fields = $this->parseFields($fieldsSpec);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        if ($fields === []) {
            $io->error('Provide at least one field to index, e.g. --fields="body".');

            return 1;
        }

        $providerClass = $className . 'SearchServiceProvider';
        $projectorClass = $className . 'SearchProjector';
        $testClass = $className . 'SearchProjectorTest';

        $providerPath = $root . '/src/Provider/' . $providerClass . '.php';
        $projectorPath = $root . '/src/Search/' . $projectorClass . '.php';
        $testPath = $root . '/tests/Search/' . $testClass . '.php';

        if (!$force) {
            foreach ([$projectorPath, $providerPath, $testPath] as $existing) {
                if (file_exists($existing)) {
                    $io->error(sprintf('%s already exists (use --force to overwrite).', $existing));

                    return 1;
                }
            }
        }

        // Single-provider refusal (fail-closed): the container resolves
        // exactly one ProvidesEntitySearchProjectorsInterface, so a second
        // generated provider would silently shadow the first depending on
        // registration order. Refuse before compiling rather than let two
        // providers land and surprise the developer at boot.
        $providerDir = $root . '/src/Provider';
        if (is_dir($providerDir)) {
            $candidates = glob($providerDir . '/*.php');
            foreach ($candidates === false ? [] : $candidates as $candidate) {
                if (realpath($candidate) === realpath($providerPath)) {
                    continue;
                }
                $contents = file_get_contents($candidate);
                if ($contents !== false && str_contains($contents, 'ProvidesEntitySearchProjectorsInterface')) {
                    $relativePath = ltrim(substr($candidate, strlen($root)), '/');
                    $io->error(sprintf(
                        'An application search-projector provider already exists at %s. The container resolves one ProvidesEntitySearchProjectorsInterface, so add %s to its entitySearchProjectors() list instead of generating a second provider.',
                        $relativePath,
                        $projectorClass,
                    ));

                    return 1;
                }
            }
        }

        try {
            $plan = new SearchProjectionScaffoldCompiler()->compile($entityTypeId, $className, $fields);
            // The single-invocation flow of ADR-025 D-6.5: compile, evaluate
            // and apply happen once, in one process, through the same
            // two-digest gate a transported plan passes. There is one
            // publication engine.
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
            $io->writeln(sprintf('Created projector: %s', $projectorPath));
            $io->writeln(sprintf('Created provider:  %s', $providerPath));
            $io->writeln(sprintf('Created test:      %s', $testPath));
            $io->writeln(in_array('composer.json', $result->changedPaths, true)
                ? 'Registered provider in composer.json (extra.waaseyaa.providers).'
                : 'Provider already registered in composer.json.');
        }
        $io->writeln('');
        $io->writeln('Note: a registered entity type defaults every undeclared field to FieldReadLevel::Internal, and index-time projection reads no Internal or Protected field. That default is deliberate — it keeps unclassified data out of a public index.');
        $io->writeln(sprintf('Each field %s indexes therefore needs a deliberate visibility decision, not a blanket one:', $projectorClass));
        $io->writeln('  - content genuinely meant for the search index: declare read: FieldReadLevel::Public on its #[Field] attribute;');
        $io->writeln('  - anything else: leave it Internal or Protected and drop it from --fields.');
        $io->writeln('Do not widen a field to Public merely to silence the omission. The generated companion test fails loudly while an indexed field is still unreadable, so the choice is made rather than defaulted.');
        $io->writeln('Then run "waaseyaa search:reindex" to build the index.');

        return 0;
    }

    /**
     * @return list<string>
     */
    private function parseFields(string $spec): array
    {
        $fields = [];
        foreach (explode(',', $spec) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $raw)) {
                throw new \RuntimeException(sprintf('Invalid field name "%s" (use snake_case).', $raw));
            }
            if (in_array($raw, $fields, true)) {
                throw new \RuntimeException(sprintf('Duplicate field "%s".', $raw));
            }
            $fields[] = $raw;
        }

        return $fields;
    }
    /**
     * The first search symbol this generator's output depends on that the
     * target cannot load, or null when the whole surface is present.
     *
     * Checked by name rather than by importing: the point is to survive the
     * very absence being detected.
     */
    private function missingSearchCapability(): ?string
    {
        $required = $this->requiredSearchSymbols ?? self::REQUIRED_SEARCH_SYMBOLS;
        foreach ($required as $symbol) {
            if (!interface_exists($symbol) && !class_exists($symbol)) {
                return $symbol;
            }
        }

        return null;
    }

}
