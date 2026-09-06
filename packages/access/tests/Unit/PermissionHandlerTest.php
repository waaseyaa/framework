<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\PermissionHandler;
use Waaseyaa\Access\PermissionHandlerInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesPermissionsInterface;

/**
 * @covers \Waaseyaa\Access\PermissionHandler
 */
class PermissionHandlerTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $handler = new PermissionHandler();

        $this->assertInstanceOf(PermissionHandlerInterface::class, $handler);
    }

    public function testEmptyByDefault(): void
    {
        $handler = new PermissionHandler();

        $this->assertSame([], $handler->getPermissions());
    }

    public function testRegisterPermission(): void
    {
        $handler = new PermissionHandler();
        $handler->registerPermission('create article', 'Create Article', 'Allows creating articles.');

        $permissions = $handler->getPermissions();

        $this->assertCount(1, $permissions);
        $this->assertArrayHasKey('create article', $permissions);
        $this->assertSame('Create Article', $permissions['create article']['title']);
        $this->assertSame('Allows creating articles.', $permissions['create article']['description']);
    }

    public function testRegisterMultiplePermissions(): void
    {
        $handler = new PermissionHandler();
        $handler->registerPermission('create article', 'Create Article');
        $handler->registerPermission('edit own article', 'Edit Own Article');
        $handler->registerPermission('administer users', 'Administer Users');

        $permissions = $handler->getPermissions();

        $this->assertCount(3, $permissions);
        $this->assertArrayHasKey('create article', $permissions);
        $this->assertArrayHasKey('edit own article', $permissions);
        $this->assertArrayHasKey('administer users', $permissions);
    }

    public function testDescriptionDefaultsToEmptyString(): void
    {
        $handler = new PermissionHandler();
        $handler->registerPermission('view content', 'View Content');

        $permissions = $handler->getPermissions();

        $this->assertSame('', $permissions['view content']['description']);
    }

    public function testHasPermissionReturnsTrueWhenRegistered(): void
    {
        $handler = new PermissionHandler();
        $handler->registerPermission('create article', 'Create Article');

        $this->assertTrue($handler->hasPermission('create article'));
    }

    public function testHasPermissionReturnsFalseWhenNotRegistered(): void
    {
        $handler = new PermissionHandler();

        $this->assertFalse($handler->hasPermission('nonexistent'));
    }

    public function testOverwritePermission(): void
    {
        $handler = new PermissionHandler();
        $handler->registerPermission('create article', 'Create Article', 'Original');
        $handler->registerPermission('create article', 'Create Article (updated)', 'Updated');

        $permissions = $handler->getPermissions();

        $this->assertCount(1, $permissions);
        $this->assertSame('Create Article (updated)', $permissions['create article']['title']);
        $this->assertSame('Updated', $permissions['create article']['description']);
    }

    // -- fromProviders(): the shared boot-time catalogue authority (#2788 G1) --

    public function testFromProvidersComposesDeclaredEntriesAndProviderContributions(): void
    {
        $handler = PermissionHandler::fromProviders(
            [$this->provider(['view article' => ['title' => 'View articles', 'description' => 'Read articles.']])],
            ['administer groups' => ['title' => 'Administer groups']],
        );

        $this->assertInstanceOf(PermissionHandlerInterface::class, $handler);
        $this->assertTrue($handler->hasPermission('administer groups'));
        $this->assertTrue($handler->hasPermission('view article'));
        $this->assertSame('', $handler->getPermissions()['administer groups']['description']);
        $this->assertSame('Read articles.', $handler->getPermissions()['view article']['description']);
    }

    public function testFromProvidersIgnoresProvidersWithoutTheCapability(): void
    {
        $handler = PermissionHandler::fromProviders([new \stdClass()]);

        $this->assertSame([], $handler->getPermissions());
    }

    public function testFromProvidersFailsClosedOnAProviderDuplicatingAnotherProvider(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Permission "view article" is declared more than once');

        PermissionHandler::fromProviders([
            $this->provider(['view article' => ['title' => 'View', 'description' => '']]),
            $this->provider(['view article' => ['title' => 'View again', 'description' => '']]),
        ]);
    }

    public function testFromProvidersFailsClosedOnAProviderDuplicatingADeclaredEntry(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Permission "administer groups" is declared more than once');

        PermissionHandler::fromProviders(
            [$this->provider(['administer groups' => ['title' => 'Again', 'description' => '']])],
            ['administer groups' => ['title' => 'Administer groups']],
        );
    }

    public function testFromProvidersRefusesAnEmptyPermissionId(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("declares an invalid permission id ''");

        PermissionHandler::fromProviders([$this->provider(['' => ['title' => 'Blank', 'description' => '']])]);
    }

    // -- fromProviders(): malformed shapes fail closed, naming the owner ----

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function malformedDefinitions(): iterable
    {
        yield 'non-array definition' => ['Title only', 'must be an array'];
        yield 'missing title' => [['description' => 'x'], 'non-empty string "title"'];
        yield 'non-string title' => [['title' => 12], 'non-empty string "title"'];
        yield 'empty title' => [['title' => '   '], 'non-empty string "title"'];
        yield 'non-string description' => [['title' => 'Ok', 'description' => ['no']], '"description" must be a string'];
        yield 'unknown member' => [['title' => 'Ok', 'scope' => 'global'], 'unknown member "scope"'];
    }

    /** @param mixed $definition */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedDefinitions')]
    public function testFromProvidersRejectsAMalformedProviderDefinitionNamingTheProvider(mixed $definition, string $expected): void
    {
        $provider = $this->provider(['view article' => $definition]);

        try {
            PermissionHandler::fromProviders([$provider]);
            $this->fail('Expected a LogicException.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('Permission "view article"', $exception->getMessage());
            $this->assertStringContainsString($expected, $exception->getMessage());
            $this->assertStringContainsString($provider::class, $exception->getMessage());
        }
    }

    /** @param mixed $definition */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedDefinitions')]
    public function testFromProvidersRejectsAMalformedManifestDefinitionNamingTheManifest(mixed $definition, string $expected): void
    {
        try {
            PermissionHandler::fromProviders([], ['view article' => $definition]);
            $this->fail('Expected a LogicException.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('Permission "view article"', $exception->getMessage());
            $this->assertStringContainsString($expected, $exception->getMessage());
            $this->assertStringContainsString('extra.waaseyaa.permissions', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{0: int|string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['  '];
        yield 'leading space' => [' view article'];
        yield 'trailing space' => ['view article '];
        yield 'control character' => ["view\tarticle"];
        yield 'integer key' => [7];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIds')]
    public function testFromProvidersRejectsAnInvalidPermissionId(int|string $id): void
    {
        $provider = $this->provider([$id => ['title' => 'Valid', 'description' => '']]);

        try {
            PermissionHandler::fromProviders([$provider]);
            $this->fail('Expected a LogicException.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('invalid permission id', $exception->getMessage());
            $this->assertStringContainsString($provider::class, $exception->getMessage());
        }
    }

    public function testFromProvidersReportsADuplicateBeforeAMalformedRedeclaration(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Permission "view article" is declared more than once');

        PermissionHandler::fromProviders(
            [$this->provider(['view article' => 'not even an array'])],
            ['view article' => ['title' => 'View']],
        );
    }

    /** @param array<int|string, mixed> $permissions */
    private function provider(array $permissions): ProvidesPermissionsInterface
    {
        return new class ($permissions) implements ProvidesPermissionsInterface {
            /** @param array<int|string, mixed> $permissions */
            public function __construct(private readonly array $permissions) {}

            public function permissions(): array
            {
                return $this->permissions;
            }
        };
    }
}
