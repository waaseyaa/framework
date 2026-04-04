<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tenancy;

use Waaseyaa\Foundation\Community\CommunityContextInterface;

/**
 * Applies community isolation to storage driver queries.
 *
 * Injected into storage drivers at wiring time — only for entity types
 * whose class implements HasCommunityInterface. Drivers call isActive()
 * before adding the community condition to avoid touching unscoped entities.
 */
final class CommunityScope
{
    public function __construct(
        private readonly CommunityContextInterface $context,
    ) {}

    /**
     * Return true when a community context is set for the current request.
     */
    public function isActive(): bool
    {
        return $this->context->isActive();
    }

    /**
     * Return the active community ID.
     *
     * Only call this after confirming isActive() === true.
     */
    public function getCommunityId(): string
    {
        $id = $this->context->get();

        if ($id === null) {
            throw new \LogicException('CommunityScope::getCommunityId() called when no community context is active.');
        }

        return $id;
    }
}
