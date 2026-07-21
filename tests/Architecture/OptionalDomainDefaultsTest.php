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
                'domains' => ['waaseyaa/oidc'],
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
}
