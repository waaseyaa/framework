<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\ServiceProvider;

use Aurora\Foundation\ServiceProvider\ProviderDiscovery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderDiscovery::class)]
final class ProviderDiscoveryTest extends TestCase
{
    #[Test]
    public function discovers_providers_from_installed_json(): void
    {
        $installed = [
            'packages' => [
                [
                    'name' => 'aurora/entity',
                    'extra' => [
                        'aurora' => [
                            'providers' => ['Aurora\\Entity\\EntityServiceProvider'],
                        ],
                    ],
                ],
                [
                    'name' => 'aurora/cache',
                    'extra' => [
                        'aurora' => [
                            'providers' => ['Aurora\\Cache\\CacheServiceProvider'],
                        ],
                    ],
                ],
                [
                    'name' => 'unrelated/package',
                    'extra' => [],
                ],
            ],
        ];

        $discovery = new ProviderDiscovery();
        $providers = $discovery->discoverFromArray($installed);

        $this->assertCount(2, $providers);
        $this->assertContains('Aurora\\Entity\\EntityServiceProvider', $providers);
        $this->assertContains('Aurora\\Cache\\CacheServiceProvider', $providers);
    }

    #[Test]
    public function skips_packages_without_aurora_extra(): void
    {
        $installed = [
            'packages' => [
                ['name' => 'symfony/console', 'extra' => []],
                ['name' => 'phpunit/phpunit'],
            ],
        ];

        $discovery = new ProviderDiscovery();
        $providers = $discovery->discoverFromArray($installed);

        $this->assertSame([], $providers);
    }

    #[Test]
    public function handles_multiple_providers_per_package(): void
    {
        $installed = [
            'packages' => [
                [
                    'name' => 'aurora/ai-schema',
                    'extra' => [
                        'aurora' => [
                            'providers' => [
                                'Aurora\\AiSchema\\SchemaServiceProvider',
                                'Aurora\\AiSchema\\McpToolServiceProvider',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $discovery = new ProviderDiscovery();
        $providers = $discovery->discoverFromArray($installed);

        $this->assertCount(2, $providers);
    }
}
