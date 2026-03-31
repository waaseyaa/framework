<?php
declare(strict_types=1);
namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use Waaseyaa\Foundation\Discovery\PackageManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageManifest::class)]
final class PackageManifestTest extends TestCase
{
    #[Test]
    public function defaults_to_empty_arrays(): void
    {
        $manifest = new PackageManifest();
        $this->assertSame([], $manifest->providers);
        $this->assertSame([], $manifest->commands);
        $this->assertSame([], $manifest->routes);
        $this->assertSame([], $manifest->migrations);
        $this->assertSame([], $manifest->fieldTypes);
        $this->assertSame([], $manifest->formatters);
        $this->assertSame([], $manifest->listeners);
        $this->assertSame([], $manifest->middleware);
        $this->assertSame([], $manifest->packageDeclarations);
    }

    #[Test]
    public function round_trips_through_array(): void
    {
        $manifest = new PackageManifest(
            providers: ['App\\Provider'],
            commands: ['App\\Command'],
            fieldTypes: ['text' => 'App\\TextField'],
            formatters: ['string' => 'App\\PlainTextFormatter'],
            middleware: ['http' => [['class' => 'App\\Mw', 'priority' => 100]]],
            packageDeclarations: [
                'waaseyaa/example' => ['surface' => 'implementation', 'activation' => 'provider'],
            ],
        );

        $array = $manifest->toArray();
        $restored = PackageManifest::fromArray($array);

        $this->assertSame($manifest->providers, $restored->providers);
        $this->assertSame($manifest->commands, $restored->commands);
        $this->assertSame($manifest->fieldTypes, $restored->fieldTypes);
        $this->assertSame($manifest->formatters, $restored->formatters);
        $this->assertSame($manifest->middleware, $restored->middleware);
        $this->assertSame($manifest->packageDeclarations, $restored->packageDeclarations);
    }

    #[Test]
    public function from_array_throws_on_missing_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required keys');

        PackageManifest::fromArray([]);
    }

    #[Test]
    public function from_array_throws_on_non_array_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an array');

        PackageManifest::fromArray([
            'providers' => 'not-an-array',
            'commands' => [],
            'routes' => [],
            'migrations' => [],
            'field_types' => [],
            'listeners' => [],
            'middleware' => [],
            'permissions' => [],
            'policies' => [],
        ]);
    }

    #[Test]
    public function from_array_defaults_missing_permissions_and_policies(): void
    {
        $manifest = PackageManifest::fromArray([
            'providers' => [],
            'commands' => [],
            'routes' => [],
            'migrations' => [],
            'field_types' => [],
            'listeners' => [],
            'middleware' => [],
        ]);

        $this->assertSame([], $manifest->permissions);
        $this->assertSame([], $manifest->policies);
        $this->assertSame([], $manifest->formatters);
        $this->assertSame([], $manifest->packageDeclarations);
    }

    #[Test]
    public function defaults_include_permissions_and_policies(): void
    {
        $manifest = new PackageManifest();
        $this->assertSame([], $manifest->permissions);
        $this->assertSame([], $manifest->policies);
        $this->assertSame([], $manifest->formatters);
        $this->assertSame([], $manifest->packageDeclarations);
    }

    #[Test]
    public function round_trips_permissions_and_policies_through_array(): void
    {
        $manifest = new PackageManifest(
            permissions: [
                'access content' => ['title' => 'Access published content'],
                'create article' => ['title' => 'Create Article content', 'description' => 'Allows creating article nodes'],
            ],
            policies: [
                'node' => 'App\\Policy\\NodePolicy',
            ],
            formatters: [
                'string' => 'App\\Formatter\\PlainTextFormatter',
            ],
            packageDeclarations: [
                'waaseyaa/example' => ['surface' => 'implementation', 'activation' => 'discovery'],
            ],
        );

        $array = $manifest->toArray();
        $restored = PackageManifest::fromArray($array);

        $this->assertSame($manifest->permissions, $restored->permissions);
        $this->assertSame($manifest->policies, $restored->policies);
        $this->assertSame($manifest->formatters, $restored->formatters);
        $this->assertSame($manifest->packageDeclarations, $restored->packageDeclarations);
    }
}
