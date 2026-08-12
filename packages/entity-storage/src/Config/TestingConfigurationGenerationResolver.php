<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Config;

use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Authority\ConfigurationGenerationResolverInterface;

/** Explicit testing-profile activation; never selected outside environment=testing. */
final class TestingConfigurationGenerationResolver implements ConfigurationGenerationResolverInterface
{
    public function __construct(private readonly DatabaseConfigurationGenerationResolver $databaseResolver) {}

    public function bind(ConfigurationAuthorityContext $context): ConfigurationAuthorityContext
    {
        $resolved = $this->databaseResolver->bind($context);
        if ($resolved->activeGenerationId !== null) {
            return $resolved;
        }

        return new ConfigurationAuthorityContext(
            authorityId: $context->authorityId,
            databaseIdentity: $context->databaseIdentity,
            syncPath: $context->syncPath,
            selectorProvenance: [...$context->selectorProvenance, 'testing-empty-generation'],
            activeGenerationId: self::generationId($context),
            activationSequence: 1,
        );
    }

    public static function generationId(ConfigurationAuthorityContext $context): string
    {
        return hash('sha256', 'configuration.testing.empty.v1|' . $context->authorityId);
    }
}
