<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;

/**
 * Discovery contract for optional imports (#2826): a cli provider that imports
 * a namespace owned by a package outside cli's runtime `require` closure must
 * declare that package through RequiresOptionalPackagesInterface. This is what
 * keeps a `suggest`-only import from becoming an unconditional registration.
 */
#[CoversNothing]
final class OptionalPackageImportDeclarationTest extends TestCase
{
    #[Test]
    public function every_provider_importing_a_non_required_package_declares_it_as_optional(): void
    {
        $packagesRoot = \dirname(__DIR__, 4);
        $cliRoot = $packagesRoot . '/cli';
        $cliManifest = $this->manifest($cliRoot . '/composer.json');

        /** @var array<string, string> $namespaceOwner prefix => package name */
        $namespaceOwner = [];
        /** @var array<string, list<string>> $requires package => runtime waaseyaa requires */
        $requires = [];
        foreach (glob($packagesRoot . '/*/composer.json') ?: [] as $manifestPath) {
            $manifest = $this->manifest($manifestPath);
            $name = $manifest['name'];
            foreach (array_keys($manifest['autoload']['psr-4'] ?? []) as $prefix) {
                $namespaceOwner[$prefix] = $name;
            }
            $requires[$name] = array_values(array_filter(
                array_keys($manifest['require'] ?? []),
                static fn(string $dependency): bool => str_starts_with($dependency, 'waaseyaa/'),
            ));
        }

        $closure = [];
        $stack = ['waaseyaa/cli'];
        while ($stack !== []) {
            $current = array_pop($stack);
            if (isset($closure[$current])) {
                continue;
            }
            $closure[$current] = true;
            array_push($stack, ...($requires[$current] ?? []));
        }

        $violations = [];
        foreach ($cliManifest['extra']['waaseyaa']['providers'] as $providerClass) {
            self::assertTrue(class_exists($providerClass), $providerClass);
            $reflection = new \ReflectionClass($providerClass);
            $source = (string) file_get_contents((string) $reflection->getFileName());
            preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?;/m', $source, $matches);

            $declared = [];
            if ($reflection->implementsInterface(RequiresOptionalPackagesInterface::class)) {
                foreach ($providerClass::optionalPackageRequirements() as $requirement) {
                    $declared[$requirement->package] = true;
                }
            }

            foreach ($matches[1] as $import) {
                $owner = $this->ownerOf($import, $namespaceOwner);
                if ($owner === null || isset($closure[$owner]) || isset($declared[$owner])) {
                    continue;
                }
                $violations[] = sprintf('%s imports %s from %s, which cli neither requires nor declares as optional', $providerClass, $import, $owner);
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    /** @param array<string, string> $namespaceOwner */
    private function ownerOf(string $import, array $namespaceOwner): ?string
    {
        $best = null;
        $bestLength = 0;
        foreach ($namespaceOwner as $prefix => $package) {
            if (str_starts_with($import, $prefix) && \strlen($prefix) > $bestLength) {
                $best = $package;
                $bestLength = \strlen($prefix);
            }
        }

        return $best;
    }

    /** @return array<string, mixed> */
    private function manifest(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
