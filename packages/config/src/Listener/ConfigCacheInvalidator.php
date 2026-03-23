<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Listener;

use Waaseyaa\Config\Event\ConfigEvent;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

final class ConfigCacheInvalidator
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $cachePath,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(ConfigEvent $event): void
    {
        try {
            if (is_file($this->cachePath)) {
                unlink($this->cachePath);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('ConfigCacheInvalidator: failed to delete %s: %s', $this->cachePath, $e->getMessage()));
        }
    }
}
