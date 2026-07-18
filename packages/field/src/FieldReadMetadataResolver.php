<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Entity\FieldReadLevel;

/**
 * Resolves additive, legacy, and site-artifact metadata without changing the
 * stable FieldDefinitionInterface contract.
 *
 * @api
 */
final class FieldReadMetadataResolver
{
    public function resolve(
        FieldDefinitionInterface $definition,
        ?FieldReadLevel $artifactLevel = null,
    ): FieldReadMetadata {
        $declared = $definition instanceof FieldReadDefinitionInterface
            ? $definition->getReadLevel()
            : null;
        $legacy = $definition->getSetting('internal') === true
            ? FieldReadLevel::Internal
            : null;

        $levels = array_values(array_filter([$declared, $legacy, $artifactLevel]));
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
        if ($artifactLevel !== null) {
            return new FieldReadMetadata($artifactLevel, FieldReadMetadataSource::ClassificationArtifact);
        }

        return new FieldReadMetadata(null, FieldReadMetadataSource::Unclassified);
    }
}
