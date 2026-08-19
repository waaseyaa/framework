<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\Config\ConfigDiffCommand;
use Waaseyaa\CLI\Command\Config\ConfigExportCommand;
use Waaseyaa\CLI\Command\Config\ConfigImportCommand;
use Waaseyaa\CLI\Command\Config\ConfigManifestSignCommand;
use Waaseyaa\CLI\Command\Config\ConfigResetCommand;
use Waaseyaa\CLI\Command\Config\ConfigStatusCommand;
use Waaseyaa\CLI\Command\Config\ConfigValidateCommand;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\AuditLogHandler;
use Waaseyaa\CLI\Handler\CacheClearHandler;
use Waaseyaa\CLI\Handler\DbInitHandler;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Authority\ActiveConfigurationBridgeInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Config\Manifest\ConfigManifestBundleSigner;
use Waaseyaa\Config\Manifest\ConfigManifestSignerInterface;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigDiffer;
use Waaseyaa\Config\Sync\ConfigExporter;
use Waaseyaa\Config\Sync\ConfigImporter;
use Waaseyaa\Config\Sync\ConfigImportPreflightInterface;
use Waaseyaa\Config\Sync\ConfigResetter;
use Waaseyaa\Config\Sync\ConfigStatusReporter;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidator;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Sync\ConfigSyncValidator;
use Waaseyaa\Config\Sync\RefusingConfigImportPreflight;
use Waaseyaa\Foundation\ServiceProvider\Capability\CapabilityRequirement;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresCapabilitiesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class ConfigCacheDbAuditServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface, RequiresCapabilitiesInterface
{
    public function register(): void
    {
        $this->singleton(ConfigSyncRepository::class, function (): ConfigSyncRepository {
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            assert($context instanceof ConfigurationAuthorityContext);

            return new ConfigSyncRepository($context->syncPath, authorityContext: $context);
        });
        $this->singleton(ConfigExporter::class, function (): ConfigExporter {
            $bridge = $this->resolve(ActiveConfigurationBridgeInterface::class);
            $repository = $this->resolve(ConfigSyncRepository::class);
            assert($bridge instanceof ActiveConfigurationBridgeInterface);
            assert($repository instanceof ConfigSyncRepository);

            return new ConfigExporter($bridge, $repository);
        });
        $this->singleton(ConfigImporter::class, function (): ConfigImporter {
            $bridge = $this->resolve(ActiveConfigurationBridgeInterface::class);
            $repository = $this->resolve(ConfigSyncRepository::class);
            $preflight = $this->kernelServices?->get(ConfigImportPreflightInterface::class);
            assert($bridge instanceof ActiveConfigurationBridgeInterface);
            assert($repository instanceof ConfigSyncRepository);
            $testing = strtolower((string) ($this->config['environment'] ?? '')) === 'testing';
            $activator = $testing ? null : $this->resolveOptional(ConfigurationActivatorInterface::class);
            if (!$testing && !$activator instanceof ConfigurationActivatorInterface) {
                throw new ConfigurationAuthorityUnavailableException(
                    'Production config:import requires the transactional configuration activation authority.',
                );
            }

            return new ConfigImporter(
                repository: $repository,
                applyHook: $bridge,
                preflight: $preflight instanceof ConfigImportPreflightInterface
                    ? $preflight
                    : new RefusingConfigImportPreflight(),
                activator: $activator,
            );
        });
        $this->singleton(ConfigDiffer::class, function (): ConfigDiffer {
            $bridge = $this->resolve(ActiveConfigurationBridgeInterface::class);
            $repository = $this->resolve(ConfigSyncRepository::class);
            assert($bridge instanceof ActiveConfigurationBridgeInterface);
            assert($repository instanceof ConfigSyncRepository);

            return new ConfigDiffer($repository, $bridge);
        });
        $this->singleton(ConfigStatusReporter::class, fn(): ConfigStatusReporter => new ConfigStatusReporter(
            $this->resolve(ConfigDiffer::class),
        ));
        $this->singleton(ConfigSyncValidator::class, fn(): ConfigSyncValidator => new ConfigSyncValidator(
            $this->resolve(ConfigSyncRepository::class),
        ));
        $this->singleton(ConfigResetter::class, function (): ConfigResetter {
            $bridge = $this->resolve(ActiveConfigurationBridgeInterface::class);
            $repository = $this->resolve(ConfigSyncRepository::class);
            assert($bridge instanceof ActiveConfigurationBridgeInterface);
            assert($repository instanceof ConfigSyncRepository);
            $testing = strtolower((string) ($this->config['environment'] ?? '')) === 'testing';
            $activator = $testing ? null : $this->resolveOptional(ConfigurationActivatorInterface::class);
            if (!$testing && !$activator instanceof ConfigurationActivatorInterface) {
                throw new ConfigurationAuthorityUnavailableException(
                    'Production config:reset requires the transactional configuration activation authority.',
                );
            }

            return new ConfigResetter(
                $repository,
                $bridge,
                activator: $activator,
                activeSource: $bridge,
            );
        });
        $this->singleton(ConfigExportCommand::class, fn(): ConfigExportCommand => new ConfigExportCommand(
            $this->resolve(ConfigExporter::class),
        ));
        // CFG-03 authoring (#2430). The signer binding exists only on a profile
        // that supplies config_manifest_signing.signing_key, so this resolves
        // lazily and the command refuses on a verifier-only host rather than the
        // provider failing to register there.
        $this->singleton(ConfigManifestSignCommand::class, function (): ConfigManifestSignCommand {
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            assert($context instanceof ConfigurationAuthorityContext);

            return new ConfigManifestSignCommand(
                function (): ?ConfigManifestBundleSigner {
                    $signer = $this->resolveOptional(ConfigManifestSignerInterface::class);
                    if (!$signer instanceof ConfigManifestSignerInterface) {
                        return null;
                    }
                    $registry = $this->resolve(ConfigSchemaRegistry::class);
                    $validator = $this->resolve(ConfigSyncBundleValidator::class);
                    $compatibility = $this->resolve(ConfigPackageCompatibility::class);
                    assert($registry instanceof ConfigSchemaRegistry);
                    assert($validator instanceof ConfigSyncBundleValidator);
                    assert($compatibility instanceof ConfigPackageCompatibility);

                    return new ConfigManifestBundleSigner($validator, $registry, $compatibility, $signer);
                },
                $context,
            );
        });
        $this->singleton(ConfigImportCommand::class, function (): ConfigImportCommand {
            $importer = $this->resolve(ConfigImporter::class);
            $bridge = $this->resolve(ActiveConfigurationBridgeInterface::class);
            assert($importer instanceof ConfigImporter);
            assert($bridge instanceof ActiveConfigurationBridgeInterface);

            return new ConfigImportCommand($importer, $bridge);
        });
        $this->singleton(ConfigDiffCommand::class, fn(): ConfigDiffCommand => new ConfigDiffCommand(
            $this->resolve(ConfigDiffer::class),
        ));
        $this->singleton(ConfigStatusCommand::class, fn(): ConfigStatusCommand => new ConfigStatusCommand(
            $this->resolve(ConfigStatusReporter::class),
        ));
        $this->singleton(ConfigValidateCommand::class, fn(): ConfigValidateCommand => new ConfigValidateCommand(
            $this->resolve(ConfigSyncValidator::class),
        ));
        $this->singleton(ConfigResetCommand::class, fn(): ConfigResetCommand => new ConfigResetCommand(
            $this->resolve(ConfigResetter::class),
        ));
    }

    public function capabilityRequirements(): iterable
    {
        yield CapabilityRequirement::exact('configuration.authority.v1', 1);
    }

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'config:export',
            description: 'Export active configuration to the sync directory',
            options: [
                new HandlerOption('diff', mode: HandlerOptionMode::None, description: 'Write only changed sync files.'),
                new HandlerOption('dry-run', mode: HandlerOptionMode::None, description: 'Preview without writing sync files.'),
            ],
            handler: [ConfigExportCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'config:manifest:sign',
            description: 'Sign the authored configuration bundle and write its envelope (authoring hosts holding signing custody only)',
            options: [
                new HandlerOption('scope', mode: HandlerOptionMode::Required, description: 'Bundle scope identifying the authoring authority; defaults to the existing envelope lineage.'),
                new HandlerOption('sequence', mode: HandlerOptionMode::Required, description: 'Monotonic bundle sequence; defaults to one past the existing envelope.'),
            ],
            handler: [ConfigManifestSignCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'config:import',
            description: 'Import configuration from the sync directory',
            options: [
                new HandlerOption('dry-run', mode: HandlerOptionMode::None, description: 'Run every preflight and preview without mutation.'),
                new HandlerOption('delete-orphans', mode: HandlerOptionMode::None, description: 'Delete active entries absent from the sync artifact.'),
                new HandlerOption('halt-on-error', mode: HandlerOptionMode::None, description: 'Stop after the first failed entry.'),
                new HandlerOption('no-dependency-check', mode: HandlerOptionMode::None, description: 'Emergency bypass for dependency ordering; does not bypass authority preflight.'),
                new HandlerOption('activation-request-id', mode: HandlerOptionMode::Required, description: 'Unique idempotency identity required for production activation.'),
                new HandlerOption('expected-generation', mode: HandlerOptionMode::Required, description: 'Expected active generation SHA-256; omit only when asserting no active generation.'),
                new HandlerOption('expected-sequence', mode: HandlerOptionMode::Required, description: 'Expected active activation sequence; supply with --expected-generation.'),
            ],
            handler: [ConfigImportCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'config:diff',
            description: 'Compare the active generation with the sync artifact',
            arguments: [new HandlerArgument('ref', HandlerArgumentMode::Optional, 'Optional <entity-type>.<id> reference.')],
            handler: [ConfigDiffCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'config:status',
            description: 'Summarize configuration drift without mutation',
            options: [new HandlerOption('format', mode: HandlerOptionMode::Required, description: 'plain or json', default: 'plain')],
            handler: [ConfigStatusCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'config:validate',
            description: 'Validate the sync artifact without mutation',
            handler: [ConfigValidateCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'config:reset',
            description: 'Reset one active entry from the sync artifact through the guarded import path',
            arguments: [new HandlerArgument('ref', HandlerArgumentMode::Required, '<entity-type>.<id> reference.')],
            options: [
                new HandlerOption('yes', mode: HandlerOptionMode::None, description: 'Skip interactive confirmation.'),
                new HandlerOption('activation-request-id', mode: HandlerOptionMode::Required, description: 'Unique idempotency identity required for production reset.'),
                new HandlerOption('expected-generation', mode: HandlerOptionMode::Required, description: 'Expected active generation SHA-256.'),
                new HandlerOption('expected-sequence', mode: HandlerOptionMode::Required, description: 'Expected active activation sequence.'),
            ],
            handler: [ConfigResetCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'cache:clear',
            description: 'Clear one or all cache bins',
            options: [
                new HandlerOption(
                    name: 'bin',
                    shortcut: 'b',
                    mode: HandlerOptionMode::Required,
                    description: 'Clear a specific cache bin instead of all bins',
                ),
                new HandlerOption(
                    name: 'tags',
                    mode: HandlerOptionMode::Required,
                    description: 'Invalidate cache entries by comma-separated tags (requires a tag-aware backend)',
                ),
            ],
            handler: [CacheClearHandler::class, 'execute'],
        );

        $projectRoot = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();
        yield self::dbInitCommand($projectRoot);

        yield new HandlerCommand(
            name: 'audit:log',
            description: 'Display the entity type lifecycle audit log, or entity-write audit log with --entity-type',
            options: [
                new HandlerOption(
                    name: 'type',
                    mode: HandlerOptionMode::Required,
                    description: 'Filter lifecycle log by entity type ID (e.g. note)',
                    default: '',
                ),
                new HandlerOption(
                    name: 'tenant',
                    mode: HandlerOptionMode::Required,
                    description: 'Filter lifecycle log by tenant ID',
                    default: '',
                ),
                new HandlerOption(
                    name: 'entity-type',
                    mode: HandlerOptionMode::Required,
                    description: 'Show entity-write audit log, optionally filtered by type (e.g. note)',
                ),
            ],
            handler: [AuditLogHandler::class, 'execute'],
        );
    }

    public static function dbInitCommand(string $projectRoot): HandlerCommand
    {
        $dbInitHandler = new DbInitHandler(projectRoot: $projectRoot);

        return new HandlerCommand(
            name: 'db:init',
            description: 'Initialize the database on first deploy: apply pending migrations and materialize every registered entity type\'s schema.',
            options: [
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Show what would happen without creating files or running migrations.',
                ),
                new HandlerOption(
                    name: 'sync-schema',
                    mode: HandlerOptionMode::None,
                    description: 'Deprecated/redundant: schema sync now runs by default. Accepted for back-compat with existing invocations.',
                ),
                new HandlerOption(
                    name: 'no-sync-schema',
                    mode: HandlerOptionMode::None,
                    description: 'Skip entity-schema materialization and run migrations only. Use when you want a migrations-only db:init.',
                ),
            ],
            handler: \Closure::fromCallable([$dbInitHandler, 'execute']),
        );
    }
}
