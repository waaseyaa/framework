<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Resource;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/** Deterministic bounded registry for MCP-neutral resource contributions. @api */
final class ContentResourceRegistry
{
    private const int MAX_RESOURCES = 50;
    private const int MAX_TEMPLATES = 20;

    /** @var array<string, ContentResourceProviderInterface> */
    private array $providers = [];
    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function register(string $name, ContentResourceProviderInterface $provider): void
    {
        if ($name === '' || isset($this->providers[$name])) {
            throw new \LogicException('Content resource provider names must be non-empty and unique.');
        }
        $this->providers[$name] = $provider;
        ksort($this->providers);
    }

    public function hasProviders(): bool
    {
        return $this->providers !== [];
    }

    /** @return list<ContentResourceDescriptor> */
    public function list(AuthorizationPrincipalInterface $principal): array
    {
        $resources = [];
        foreach ($this->providers as $providerName => $provider) {
            foreach ($provider->list($principal) as $resource) {
                if (isset($resources[$resource->uri])) {
                    $this->logger->warning('Duplicate content resource URI omitted.', ['provider' => $providerName]);
                    continue;
                }
                $resources[$resource->uri] = $resource;
                if (count($resources) === self::MAX_RESOURCES) {
                    break 2;
                }
            }
        }

        return array_values($resources);
    }

    /** @return list<ContentResourceTemplate> */
    public function templates(): array
    {
        $templates = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->templates() as $template) {
                $templates[$template->uriTemplate] ??= $template;
                if (count($templates) === self::MAX_TEMPLATES) {
                    break 2;
                }
            }
        }

        return array_values($templates);
    }

    public function read(string $uri, AuthorizationPrincipalInterface $principal): ?ContentResourceContent
    {
        foreach ($this->providers as $provider) {
            $content = $provider->read($uri, $principal);
            if ($content !== null) {
                return $content;
            }
        }

        return null;
    }
}
