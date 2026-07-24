<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Access\CompiledFieldReadRule;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Resolves additive, legacy, and site-artifact metadata without changing the
 * stable FieldDefinitionInterface contract.
 *
 * @api
 */
final class FieldReadMetadataResolver
{
    /** @var \WeakMap<FieldDefinitionInterface, CompiledFieldReadRule> */
    private \WeakMap $compiledRules;

    public function __construct()
    {
        $this->compiledRules = new \WeakMap();
    }

    /** @internal field/entity composition only */
    public function compile(FieldDefinitionInterface $definition): CompiledFieldReadRule
    {
        return $this->compiledRules[$definition] ??= new CompiledFieldReadRule(
            $definition->getName(),
            $this->resolve($definition)->level ?? FieldReadLevel::Internal,
        );
    }

    public function resolve(
        FieldDefinitionInterface $definition,
        ?FieldReadLevel $artifactLevel = null,
        ?FieldReadLevel $frameworkDefaultLevel = null,
    ): FieldReadMetadata {
        $declared = $definition instanceof FieldReadDefinitionInterface
            ? $definition->getReadLevel()
            : null;
        $legacy = $definition->getSetting('internal') === true
            ? FieldReadLevel::Internal
            : null;

        $levels = array_values(array_filter([$declared, $legacy, $frameworkDefaultLevel, $artifactLevel]));
        foreach ($levels as $level) {
            if ($level !== $levels[0]) {
                throw new \LogicException(sprintf(
                    'Conflicting field-read metadata for field "%s".',
                    $definition->getName(),
                ));
            }
        }

        if ($declared !== null) {
            return new FieldReadMetadata($declared, FieldReadMetadataSource::Definition);
        }
        if ($legacy !== null) {
            return new FieldReadMetadata($legacy, FieldReadMetadataSource::LegacyInternal);
        }
        if ($frameworkDefaultLevel !== null) {
            return new FieldReadMetadata($frameworkDefaultLevel, FieldReadMetadataSource::FrameworkDefault);
        }
        if ($artifactLevel !== null) {
            return new FieldReadMetadata($artifactLevel, FieldReadMetadataSource::ClassificationArtifact);
        }

        return new FieldReadMetadata(null, FieldReadMetadataSource::Unclassified);
    }
}
