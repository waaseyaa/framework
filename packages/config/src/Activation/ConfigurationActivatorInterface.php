<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Sync\ConfigSyncFile;

/** Atomic generation activation seam implemented by the storage layer. @api */
interface ConfigurationActivatorInterface
{
    public function activate(ConfigurationActivationRequest $request): ConfigurationActivationResult;

    public function rollback(ConfigurationRollbackRequest $request): ConfigurationActivationResult;

    public function committedResult(string $requestId): ?ConfigurationActivationResult;

    public function currentToken(): ?ConfigurationActiveToken;

    /** @return iterable<ConfigSyncFile> */
    public function readGeneration(ConfigurationActiveToken $token): iterable;
}
