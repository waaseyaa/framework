<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

/**
 * What evaluation determined would happen to one planned artifact (ADR-025 D-6.2).
 *
 * This is the per-path detail of the computation `SiteInitializationService`
 * already performs and today surfaces only as a flat list of changed paths.
 * It is evaluation output, never compiler output: a pure plan cannot know
 * whether a file it renders already exists.
 *
 * @api
 */
enum ArtifactStatus: string
{
    case Created = 'created';
    case Changed = 'changed';
    case Unchanged = 'unchanged';
    case Refused = 'refused';
}
