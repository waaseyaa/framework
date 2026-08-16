<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Config;

use Waaseyaa\Config\Activation\ConfigurationActivationAuthorizerInterface;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Activation\ConfigurationCandidateMaintenanceInterface;
use Waaseyaa\Config\Activation\ConfigurationCandidateSweepAuthorizerInterface;
use Waaseyaa\Config\Activation\ConfigurationRollbackValidatorInterface;
use Waaseyaa\Config\Activation\RefusingConfigurationActivationAuthorizer;
use Waaseyaa\Config\Activation\RefusingConfigurationCandidateSweepAuthorizer;
use Waaseyaa\Config\Activation\RefusingConfigurationRollbackValidator;
use Waaseyaa\Config\Authority\ActiveConfigurationBridgeInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Authority\ConfigurationGenerationResolverInterface;
use Waaseyaa\Config\Drift\ConfigDriftSnapshotReaderInterface;
use Waaseyaa\Config\Manifest\ConfigReplayStateReaderInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Runtime\RuntimeEpochInterface;
use Waaseyaa\Foundation\Runtime\StableRuntimeEpoch;
use Waaseyaa\Foundation\ServiceProvider\Capability\CapabilityRequirement;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresCapabilitiesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/** Higher-layer implementation of the configuration authority seams. */
final class ConfigurationStorageServiceProvider extends ServiceProvider implements RequiresCapabilitiesInterface
{
    public function register(): void
    {
        $testing = strtolower((string) ($this->config['environment'] ?? '')) === 'testing';
        $this->singleton(ConfigurationGenerationResolverInterface::class, function () use ($testing): ConfigurationGenerationResolverInterface {
            $database = $this->resolve(DatabaseInterface::class);
            assert($database instanceof DatabaseInterface);

            $resolver = new DatabaseConfigurationGenerationResolver($database);

            return $testing ? new TestingConfigurationGenerationResolver($resolver) : $resolver;
        });
        $this->singleton(ActiveConfigurationBridgeInterface::class, function () use ($testing): ActiveConfigurationBridgeInterface {
            $database = $this->resolve(DatabaseInterface::class);
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            assert($database instanceof DatabaseInterface);
            assert($context instanceof ConfigurationAuthorityContext);

            if ($testing && $context->activeGenerationId === TestingConfigurationGenerationResolver::generationId($context)) {
                return new TestingActiveConfigurationBridge($context);
            }

            return new DatabaseActiveConfigurationBridge($database, $context);
        });
        $this->singleton(ConfigurationActivatorInterface::class, function (): ConfigurationActivatorInterface {
            $database = $this->resolve(DatabaseInterface::class);
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            $authorizer = $this->resolveOptional(ConfigurationActivationAuthorizerInterface::class)
                ?? new RefusingConfigurationActivationAuthorizer();
            $rollbackValidator = $this->resolveOptional(ConfigurationRollbackValidatorInterface::class)
                ?? new RefusingConfigurationRollbackValidator();
            $sweepAuthorizer = $this->resolveOptional(ConfigurationCandidateSweepAuthorizerInterface::class)
                ?? new RefusingConfigurationCandidateSweepAuthorizer();
            assert($database instanceof DatabaseInterface);
            assert($context instanceof ConfigurationAuthorityContext);
            assert($authorizer instanceof ConfigurationActivationAuthorizerInterface);
            assert($rollbackValidator instanceof ConfigurationRollbackValidatorInterface);
            assert($sweepAuthorizer instanceof ConfigurationCandidateSweepAuthorizerInterface);

            return new DatabaseConfigurationActivator($database, $context, $authorizer, $rollbackValidator, $sweepAuthorizer);
        });
        $this->singleton(ConfigurationCandidateMaintenanceInterface::class, function (): ConfigurationCandidateMaintenanceInterface {
            $activator = $this->resolve(ConfigurationActivatorInterface::class);
            assert($activator instanceof ConfigurationCandidateMaintenanceInterface);

            return $activator;
        });
        $this->singleton(ConfigReplayStateReaderInterface::class, function (): ConfigReplayStateReaderInterface {
            $database = $this->resolve(DatabaseInterface::class);
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            assert($database instanceof DatabaseInterface);
            assert($context instanceof ConfigurationAuthorityContext);

            return new DatabaseConfigReplayStateReader($database, $context);
        });
        $this->singleton(ConfigDriftSnapshotReaderInterface::class, function (): ConfigDriftSnapshotReaderInterface {
            $database = $this->resolve(DatabaseInterface::class);
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            $bridge = $this->resolve(ActiveConfigurationBridgeInterface::class);
            assert($database instanceof DatabaseInterface);
            assert($context instanceof ConfigurationAuthorityContext);
            assert($bridge instanceof ActiveConfigurationBridgeInterface);

            return new DatabaseConfigDriftSnapshotReader($database, $context, $bridge);
        });
        $this->singleton(RuntimeEpochInterface::class, function () use ($testing): RuntimeEpochInterface {
            if ($testing) {
                return new StableRuntimeEpoch();
            }
            $activator = $this->resolve(ConfigurationActivatorInterface::class);
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            assert($activator instanceof ConfigurationActivatorInterface);
            assert($context instanceof ConfigurationAuthorityContext);

            return new ConfigurationRuntimeEpoch(
                $activator,
                new \Waaseyaa\Config\Authority\ConfigurationActiveToken(
                    $context->requireActiveGenerationId(),
                    $context->activationSequence
                        ?? throw new \LogicException('Active configuration sequence is unavailable.'),
                ),
            );
        });
    }

    public function capabilityRequirements(): iterable
    {
        yield CapabilityRequirement::exact('configuration.authority.v1', 1);
    }
}
