<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OptionalDomainDefaultsTest extends TestCase
{
    /** @var list<string> */
    private const OPTIONAL_DOMAINS = [
        'waaseyaa/ai-agent',
        'waaseyaa/engagement',
        'waaseyaa/genealogy',
        'waaseyaa/mcp',
        'waaseyaa/messaging',
        'waaseyaa/oidc',
        'waaseyaa/wayfinding',
    ];

    #[Test]
    public function curated_defaults_do_not_select_optional_domains_directly(): void
    {
        $root = dirname(__DIR__, 2);
        $manifests = [
            'waaseyaa/framework' => $root . '/composer.json',
            'waaseyaa/core' => $root . '/packages/core/composer.json',
            'waaseyaa/cms' => $root . '/packages/cms/composer.json',
            'waaseyaa/full' => $root . '/packages/full/composer.json',
        ];

        foreach ($manifests as $package => $path) {
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $selected = array_keys((array) ($manifest['require'] ?? []));

            foreach (self::OPTIONAL_DOMAINS as $optionalDomain) {
                self::assertNotContains(
                    $optionalDomain,
                    $selected,
                    sprintf('%s must not select opt-in domain %s.', $package, $optionalDomain),
                );
            }
        }
    }

    #[Test]
    public function reusable_packages_do_not_force_unrelated_optional_domains(): void
    {
        $root = dirname(__DIR__, 2);
        $forbiddenEdges = [
            'waaseyaa/cli' => [
                'manifest' => $root . '/packages/cli/composer.json',
                'domains' => ['waaseyaa/ai-agent', 'waaseyaa/oidc'],
            ],
            'waaseyaa/api' => [
                'manifest' => $root . '/packages/api/composer.json',
                'domains' => ['waaseyaa/auth', 'waaseyaa/oidc', 'waaseyaa/search'],
            ],
            'waaseyaa/routing' => [
                'manifest' => $root . '/packages/routing/composer.json',
                'domains' => ['waaseyaa/oidc'],
            ],
            'waaseyaa/ai-agent' => [
                'manifest' => $root . '/packages/ai-agent/composer.json',
                'domains' => ['waaseyaa/wayfinding'],
            ],
        ];

        foreach ($forbiddenEdges as $package => $expectation) {
            $manifest = json_decode((string) file_get_contents($expectation['manifest']), true, 512, JSON_THROW_ON_ERROR);
            $requires = array_keys((array) ($manifest['require'] ?? []));

            foreach ($expectation['domains'] as $optionalDomain) {
                self::assertNotContains(
                    $optionalDomain,
                    $requires,
                    sprintf('%s must not force optional domain %s.', $package, $optionalDomain),
                );
            }
        }
    }

    #[Test]
    public function api_declares_public_search_as_an_optional_version_coupled_domain(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode(
            (string) file_get_contents($root . '/packages/api/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach (['waaseyaa/auth', 'waaseyaa/search'] as $package) {
            self::assertArrayNotHasKey($package, $manifest['require']);
            self::assertArrayHasKey($package, $manifest['suggest']);
            self::assertSame('^0.1.0-alpha.286', $manifest['require-dev'][$package]);
            self::assertSame('<0.1.0-alpha.287 || >=0.2.0', $manifest['conflict'][$package]);
        }
    }

    #[Test]
    public function api_source_keeps_optional_search_and_auth_types_behind_its_local_ports(): void
    {
        $root = dirname(__DIR__, 2);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root . '/packages/api/src',
            \FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression(
                '/use Waaseyaa\\\\(?:Auth|Search)\\\\/',
                $source,
                $file->getPathname() . ' must use the API-owned optional-domain adapter boundary.',
            );
        }
    }
}
