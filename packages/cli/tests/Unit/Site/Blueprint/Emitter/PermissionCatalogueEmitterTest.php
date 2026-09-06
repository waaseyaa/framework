<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint\Emitter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\PermissionHandler;
use Waaseyaa\CLI\Site\Blueprint\Emitter\PermissionCatalogueEmitter;
use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintPermission;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(PermissionCatalogueEmitter::class)]
final class PermissionCatalogueEmitterTest extends TestCase
{
    #[Test]
    public function idIsStable(): void
    {
        self::assertSame('permission-catalogue', new PermissionCatalogueEmitter()->id());
    }

    #[Test]
    public function itEmitsNothingWhenTheBlueprintDeclaresNoPermissionsMatchingTheMinimalGoldenFixture(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $emission = new PermissionCatalogueEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertSame([], $emission->artifacts);
        self::assertSame([], $emission->registrations);
        self::assertSame([], $emission->companionTests);
    }

    #[Test]
    public function itMatchesTheCompleteGoldenFixture(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new PermissionCatalogueEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertCount(1, $emission->artifacts);
        self::assertSame('src/Access/ApplicationBlueprintPermissions.php', $emission->artifacts[0]->path);
        self::assertSame($this->expected('complete/src/Access/ApplicationBlueprintPermissions.php'), $emission->artifacts[0]->content);
        self::assertSame([], $emission->registrations);
        self::assertSame([], $emission->companionTests);
    }

    /**
     * The emitted class must actually work as the real
     * `Waaseyaa\Access\PermissionHandler::registerPermission()` boundary
     * expects — loaded and exercised through the real runtime, not a
     * snapshot comparison alone.
     */
    #[Test]
    public function theGeneratedClassRegistersEveryPermissionThroughTheRealPermissionHandler(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new PermissionCatalogueEmitter()->emit($manifest->applicationBlueprint, $manifest);

        $namespace = 'Waaseyaa\\CLI\\Tests\\BlueprintPermissionCatalogue' . bin2hex(random_bytes(4));
        $source = str_replace('namespace App\\Access;', 'namespace ' . $namespace . ';', $emission->artifacts[0]->content);

        $file = tempnam(sys_get_temp_dir(), 'waaseyaa_permission_catalogue_') . '.php';
        file_put_contents($file, $source);
        try {
            require $file;
            $class = $namespace . '\\ApplicationBlueprintPermissions';

            self::assertSame('edit article', $class::PERMISSION_EDIT_ARTICLE);
            self::assertSame('view article', $class::PERMISSION_VIEW_ARTICLE);
            self::assertSame('use editorial transition publish', $class::PERMISSION_USE_EDITORIAL_TRANSITION_PUBLISH);

            $handler = new PermissionHandler();
            $class::register($handler);

            self::assertTrue($handler->hasPermission('edit article'));
            self::assertTrue($handler->hasPermission('view article'));
            self::assertTrue($handler->hasPermission('use editorial transition publish'));
            self::assertSame('Edit articles', $handler->getPermissions()['edit article']['title']);
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function twoPermissionsCollidingOnTheSameConstantNameAreRefusedGen006(): void
    {
        $manifest = $this->manifestWithBlueprint($this->blueprintWithPermissions([
            new BlueprintPermission('edit article', 'Edit articles'),
            new BlueprintPermission('edit_article', 'Also edit articles'),
        ]));

        try {
            new PermissionCatalogueEmitter()->emit($manifest->applicationBlueprint, $manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::MaliciousIdentifier, $exception->violations[0]->code);
            self::assertStringContainsString('EDIT_ARTICLE', $exception->violations[0]->message);
        }
    }

    /** @param list<BlueprintPermission> $permissions */
    private function blueprintWithPermissions(array $permissions): ApplicationBlueprint
    {
        $byId = [];
        foreach ($permissions as $permission) {
            $byId[$permission->id] = $permission;
        }

        return new ApplicationBlueprint(
            contractVersion: 1,
            entities: [],
            relationships: [],
            permissions: $byId,
            roles: [],
            policies: [],
            workflows: [],
            fixtures: [],
            checks: [],
        );
    }

    private function manifestWithBlueprint(ApplicationBlueprint $blueprint): SiteManifest
    {
        $parsed = $this->manifest('minimal.yaml');

        return new SiteManifest(
            $parsed->schemaVersion,
            $parsed->generatorVersion,
            $parsed->application,
            $parsed->framework,
            $parsed->contentTypes,
            $parsed->capabilities,
            $parsed->personalDataStores,
            $parsed->recipes,
            $parsed->verificationCommand,
            $parsed->canonicalJson,
            $parsed->digest,
            $blueprint,
            $parsed->requiredGeneratorFeatures,
        );
    }

    private function manifest(string $fixture): SiteManifest
    {
        $yaml = (string) file_get_contents(
            \dirname(__DIR__, 6) . '/site-contract/tests/Fixtures/Blueprint/valid/' . $fixture,
        );

        return new SiteManifestParser()->parse($yaml, $fixture);
    }

    private function expected(string $relativePath): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 4) . '/Fixtures/Blueprint/expected/' . $relativePath);
    }
}
