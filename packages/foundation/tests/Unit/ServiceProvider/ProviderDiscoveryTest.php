<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\ServiceProvider;

use Waaseyaa\Foundation\ServiceProvider\ProviderDiscovery;
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
                    'name' => 'waaseyaa/entity',
                    'extra' => [
                        'waaseyaa' => [
                            'providers' => ['Waaseyaa\\Entity\\EntityServiceProvider'],
                        ],
                    ],
                ],
                [
                    'name' => 'waaseyaa/cache',
                    'extra' => [
                        'waaseyaa' => [
                            'providers' => ['Waaseyaa\\Cache\\CacheServiceProvider'],
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
        $this->assertContains('Waaseyaa\\Entity\\EntityServiceProvider', $providers);
        $this->assertContains('Waaseyaa\\Cache\\CacheServiceProvider', $providers);
    }

    #[Test]
    public function skips_packages_without_waaseyaa_extra(): void
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
        // Provider FQCNs are arbitrary fixture strings (discoverFromArray only parses
        // the array shape; it never autoloads or resolves the class), so the package
        // metadata name and the provider namespace deliberately do not need to match a
        // real waaseyaa/* package. Not "Waaseyaa\…"-namespaced since it names no real
        // class in any package (bin/check-package-layers PL010 fails closed on FQCNs
        // that resolve to no known PSR-4 root).
        $installed = [
            'packages' => [
                [
                    'name' => 'waaseyaa/ai-schema',
                    'extra' => [
                        'waaseyaa' => [
                            'providers' => [
                                'Acme\\AiSchema\\SchemaServiceProvider',
                                'Acme\\AiSchema\\McpToolServiceProvider',
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
