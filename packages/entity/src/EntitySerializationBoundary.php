<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Waaseyaa\Entity\Exception\EntitySerializationForbidden;

/** Activation-ready closed PHP serialization entry point for entity values. @api */
final readonly class EntitySerializationBoundary
{
    public function __construct(private EntitySerializationBoundaryConfig $config) {}

    public function serialize(EntityInterface $entity): string
    {
        if ($this->config->rejectSerialization) {
            throw new EntitySerializationForbidden(sprintf(
                'Entity %s cannot be PHP-serialized; persist an identifier or explicit public projection.',
                $entity->getEntityTypeId(),
            ));
        }

        return serialize($entity);
    }
}
