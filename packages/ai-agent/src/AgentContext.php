<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent;

use Waaseyaa\Access\AccountInterface;

/**
 * Context passed to agents during execution.
 *
 * Contains the user account the agent acts as, agent-specific parameters,
 * and whether this is a dry run.
 */
final readonly class AgentContext
{
    /**
     * @param AccountInterface $account The user the agent acts as
     * @param array<string, mixed> $parameters Agent-specific parameters
     * @param bool $dryRun Whether this is a dry run
     * @param int $maxIterations Maximum tool loop iterations for provider execution
     */
    public function __construct(
        public AccountInterface $account,
        public array $parameters = [],
        public bool $dryRun = false,
        public int $maxIterations = 25,
    ) {}
}
