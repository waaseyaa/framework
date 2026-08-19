<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

/**
 * The installation-only seam that creates a site's first configuration
 * generation (#2428).
 *
 * Kept off ConfigurationActivatorInterface deliberately. Ordinary activation
 * consumers resolve that interface and cannot reach this operation, so genesis
 * is only available to code that asks for it by name — in practice the
 * restricted `install:init` lifecycle. It is not a second authority and not a
 * general verification bypass: it can produce exactly one thing, the canonical
 * empty generation, and only when no generation is active.
 *
 * @api
 */
interface ConfigurationGenesisActivatorInterface
{
    /**
     * Atomically create and activate the canonical empty generation.
     *
     * Returns the previously committed result when the same installation
     * request already succeeded, so an interrupted install is safe to retry.
     * Refuses when a different generation is already active rather than
     * creating a competing one.
     */
    public function activateGenesis(string $requestId): ConfigurationActivationResult;
}
