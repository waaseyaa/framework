<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Mcp;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Mcp\Registry\McpRegistryManifest;

/**
 * `mcp:registry-manifest` — emit the official Registry `server.json` (#2638).
 *
 * Writes only the manifest JSON to stdout. Configuration refusals go to
 * stderr and exit non-zero so a pipeline can capture the artifact without
 * stripping diagnostics.
 *
 * @api
 */
final class McpRegistryManifestCommand
{
    /** @param \Closure(): McpRegistryManifest $manifest */
    public function __construct(private readonly \Closure $manifest) {}

    public function execute(SymfonyCommandIO $io): int
    {
        try {
            $io->writeRaw(($this->manifest)()->toJson());
        } catch (ConfigException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        return 0;
    }
}
