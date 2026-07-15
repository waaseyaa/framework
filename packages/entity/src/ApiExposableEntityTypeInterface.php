<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Opt-in capability for entity types that deliberately expose generic JSON:API routes.
 *
 * @api
 */
interface ApiExposableEntityTypeInterface
{
    public function isApiExposed(): bool;
}
