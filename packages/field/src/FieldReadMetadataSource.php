<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

/** @api */
enum FieldReadMetadataSource: string
{
    case Definition = 'definition';
    case LegacyInternal = 'legacy_internal';
    case FrameworkDefault = 'framework_default';
    case ClassificationArtifact = 'classification_artifact';
    case Unclassified = 'unclassified';
}
