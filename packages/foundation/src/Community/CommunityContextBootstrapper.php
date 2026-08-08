<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Community;

/**
 * Builds the fixed application community context used by single-community
 * deployments and CLI processes.
 */
final class CommunityContextBootstrapper
{
    /** @param array<string, mixed> $config */
    public function boot(array $config): ?CommunityContextInterface
    {
        $configured = $config['community_id'] ?? null;
        if ($configured === null || $configured === '') {
            return null;
        }
        if (!is_string($configured)) {
            throw new \InvalidArgumentException('The community_id configuration value must be a string.');
        }

        $communityId = trim($configured);
        if ($communityId === '') {
            throw new \InvalidArgumentException('The community_id configuration value must not be blank.');
        }

        $context = new CommunityContext();
        $context->set($communityId);

        return $context;
    }
}
