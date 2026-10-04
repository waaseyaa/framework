<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Graph;

use Waaseyaa\Foundation\Log\LoggerInterface;

/**
 * @api
 */
final class ApplicationGraphGenerator
{
    private const string GRAPH_VERSION = '1.0';

    /** @param iterable<GraphSectionProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $strict = false,
        /** Recheck kernel route custody after every section has returned. */
        private readonly ?\Closure $routeAuthorityCheck = null,
    ) {}

    public function generate(): ApplicationGraph
    {
        $sections = [];

        foreach ($this->providers as $provider) {
            try {
                $sections[] = $provider->provide();
            } catch (\Throwable $e) {
                if ($this->strict) {
                    throw $e;
                }

                $this->logger?->warning(
                    "Bimaaji: provider '{$provider->getKey()}' failed, skipping",
                    ['exception' => $e],
                );
            }
        }

        ($this->routeAuthorityCheck)?->__invoke();
        return new ApplicationGraph(self::GRAPH_VERSION, $sections);
    }
}
