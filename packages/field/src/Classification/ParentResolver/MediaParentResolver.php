<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification\ParentResolver;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Field\Classification\ClassificationParentResolverInterface;

/**
 * Stock parent resolver for 'media' entities.
 *
 * Media items may be attached to a parent node. The framework does not own
 * the node–media relationship — that belongs to the attachment or media
 * package. This stub resolver always returns null; host applications that
 * model media–node ownership should replace this resolver by registering
 * their own implementation with LabelInheritanceResolver::addResolver().
 *
 * @api
 */
final class MediaParentResolver implements ClassificationParentResolverInterface
{
    public function getSupportedEntityTypeId(): string
    {
        return 'media';
    }

    /**
     * Returns null — the media–node parent relationship is application-defined.
     */
    public function resolveParent(EntityInterface $entity): ?EntityInterface
    {
        return null;
    }
}
