<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Entity\FieldReadLevel;

/**
 * Additive companion for definitions that declare field-read metadata.
 *
 * A null level is deliberately distinct from Public: it is unclassified and
 * remains compatibility-Public only until the activation preflight succeeds.
 *
 * @api
 */
interface FieldReadDefinitionInterface
{
    public function getReadLevel(): ?FieldReadLevel;
}
