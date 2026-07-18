<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Exception;

/**
 * Future activation error for attempts to serialize value-bearing entities.
 *
 * This type is intentionally not wired to EntityBase during dormant WP1.
 */
final class EntitySerializationForbidden extends \LogicException {}
