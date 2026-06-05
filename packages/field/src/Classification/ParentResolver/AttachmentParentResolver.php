<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification\ParentResolver;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Field\Classification\ClassificationParentResolverInterface;

/**
 * Stock parent resolver for 'attachment' entities.
 *
 * Attachments are subordinate to a host entity (typically a node). The
 * framework does not own the attachment–host relationship directly. This stub
 * resolver always returns null; host applications that model the attachment
 * parentage should register their own resolver via
 * LabelInheritanceResolver::addResolver().
 *
 * @api
 */
final class AttachmentParentResolver implements ClassificationParentResolverInterface
{
    public function getSupportedEntityTypeId(): string
    {
        return 'attachment';
    }

    /**
     * Returns null — attachment parentage is application-defined.
     */
    public function resolveParent(EntityInterface $entity): ?EntityInterface
    {
        return null;
    }
}
