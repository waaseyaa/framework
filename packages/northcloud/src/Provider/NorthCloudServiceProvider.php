<?php

declare(strict_types=1);

namespace Waaseyaa\NorthCloud\Provider;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\NorthCloud\Client\NorthCloudCache;
use Waaseyaa\NorthCloud\Client\NorthCloudClient;
use Waaseyaa\NorthCloud\Search\NorthCloudSearchProvider;
use Waaseyaa\NorthCloud\Sync\MapperRegistry;
use Waaseyaa\NorthCloud\Sync\NcSyncService;

/**
 * Wires the NorthCloud package into a Waaseyaa application.
 *
 * Registers the HTTP client, mapper registry, sync service, and search provider.
 * Concrete NcHitToEntityMapperInterface implementations are registered by the
 * application (typically in its own service provider).
 *
 * Config is read from the `northcloud` key. See config/northcloud.php for
 * shipped defaults.
 *
 * CLI command wiring (northcloud:sync) is handled by the CLI-layer
 * NorthCloudServiceProvider in packages/cli.
 */
final class NorthCloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(MapperRegistry::class, fn(): MapperRegistry => new MapperRegistry());

        $this->singleton(NorthCloudCache::class, function (): NorthCloudCache {
            $config = $this->northcloudConfig();

            return new NorthCloudCache(
                $this->resolve(\PDO::class),
                (int) ($config['cache']['ttl'] ?? 3600),
            );
        });

        $this->singleton(NorthCloudClient::class, function (): NorthCloudClient {
            $config = $this->northcloudConfig();
            $logger = $this->resolveLogger();

            $cache = null;
            if ((bool) ($config['cache']['enabled'] ?? false)) {
                try {
                    $cache = $this->resolve(NorthCloudCache::class);
                } catch (\Throwable $e) {
                    // Cache is optional: log the failure (instead of swallowing it
                    // silently) and degrade to running without a response cache.
                    $logger?->warning(sprintf(
                        'NorthCloud cache unavailable, proceeding without response caching: %s',
                        $e->getMessage(),
                    ));
                }
            }

            return new NorthCloudClient(
                baseUrl: (string) ($config['base_url'] ?? ''),
                timeout: (int) ($config['timeout'] ?? 5),
                cache: $cache,
                apiToken: (string) ($config['api_token'] ?? ''),
                allowInsecure: (bool) ($config['allow_insecure'] ?? false),
                logger: $logger,
            );
        });

        $this->singleton(NcSyncService::class, function (): NcSyncService {
            $config = $this->northcloudConfig();

            return new NcSyncService(
                client: $this->resolve(NorthCloudClient::class),
                entityTypeManager: $this->resolve(\Waaseyaa\Entity\EntityTypeManager::class),
                mappers: $this->resolve(MapperRegistry::class),
                topics: (array) ($config['sync']['topics'] ?? ['indigenous']),
                minQuality: (int) ($config['sync']['min_quality'] ?? 60),
                logger: $this->resolveLogger(),
            );
        });

        $this->singleton(NorthCloudSearchProvider::class, function (): NorthCloudSearchProvider {
            $config = $this->northcloudConfig();

            return new NorthCloudSearchProvider(
                client: $this->resolve(NorthCloudClient::class),
                cacheTtl: (int) ($config['search']['cache_ttl'] ?? 300),
            );
        });
    }

    /**
     * Resolve the framework logger without throwing when no binding exists
     * (e.g. minimal test kernels). Null-safe callers degrade to their own
     * NullLogger default.
     */
    private function resolveLogger(): ?LoggerInterface
    {
        $logger = $this->resolveOptional(LoggerInterface::class);

        return $logger instanceof LoggerInterface ? $logger : null;
    }

    /**
     * Read the `northcloud` config section, falling back to env vars when the
     * host app hasn't merged the package config. This lets consumers drop the
     * package in and set `NORTHCLOUD_BASE_URL` / `NORTHCLOUD_API_TOKEN` in
     * `.env` without having to publish the config file first.
     *
     * The provider is the single authority for reading NORTHCLOUD_* env vars;
     * the shipped config file (config/northcloud.php) uses plain string
     * defaults so there's no eager-read-vs-lazy-read drift.
     *
     * Env vars win over config defaults when set; empty config values fall
     * back to env vars which fall back to package defaults.
     *
     * @return array<string, mixed>
     */
    public function northcloudConfig(): array
    {
        $section = $this->config['northcloud'] ?? [];
        $section = is_array($section) ? $section : [];

        $envBaseUrl = getenv('NORTHCLOUD_BASE_URL');
        if ($envBaseUrl !== false && $envBaseUrl !== '') {
            $section['base_url'] = $envBaseUrl;
        } elseif (!isset($section['base_url']) || $section['base_url'] === '') {
            $section['base_url'] = 'https://api.northcloud.one';
        }

        $envToken = getenv('NORTHCLOUD_API_TOKEN');
        if ($envToken !== false && $envToken !== '') {
            $section['api_token'] = $envToken;
        } elseif (!isset($section['api_token'])) {
            $section['api_token'] = '';
        }

        $envAllowInsecure = getenv('NORTHCLOUD_ALLOW_INSECURE');
        if ($envAllowInsecure !== false && $envAllowInsecure !== '') {
            $section['allow_insecure'] = filter_var($envAllowInsecure, FILTER_VALIDATE_BOOLEAN);
        } elseif (!isset($section['allow_insecure'])) {
            // Auto-permit http only for obvious loopback dev targets; production URLs stay strict.
            $baseUrl = (string) ($section['base_url'] ?? '');
            $section['allow_insecure'] = str_starts_with($baseUrl, 'http://localhost')
                || str_starts_with($baseUrl, 'http://127.0.0.1');
        }

        return $section;
    }
}
