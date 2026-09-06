<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint\Emitter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\PermissionHandler;
use Waaseyaa\CLI\Site\Blueprint\Emitter\GovernanceProviderEmitter;
use Waaseyaa\CLI\Site\Blueprint\Emitter\PermissionCatalogueEmitter;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesPermissionsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;
use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintRole;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;
use Waaseyaa\User\RoleRepository;

#[CoversClass(GovernanceProviderEmitter::class)]
final class GovernanceProviderEmitterTest extends TestCase
{
    #[Test]
    public function idIsStable(): void
    {
        self::assertSame('governance-provider', new GovernanceProviderEmitter()->id());
    }

    #[Test]
    public function itEmitsNothingWhenTheBlueprintDeclaresNoRolesOrWorkflowsMatchingTheMinimalGoldenFixture(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $emission = new GovernanceProviderEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertSame([], $emission->artifacts);
        self::assertSame([], $emission->registrations);
    }

    #[Test]
    public function itMatchesTheCompleteGoldenFixtureWithADistinctProviderFqcn(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new GovernanceProviderEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertCount(1, $emission->artifacts);
        self::assertSame('src/Provider/ApplicationBlueprintGovernanceServiceProvider.php', $emission->artifacts[0]->path);
        self::assertSame($this->expected('complete/src/Provider/ApplicationBlueprintGovernanceServiceProvider.php'), $emission->artifacts[0]->content);

        self::assertCount(1, $emission->registrations);
        self::assertSame('App\\Provider\\ApplicationBlueprintGovernanceServiceProvider', $emission->registrations[0]->fqcn);
        self::assertNotSame('App\\Provider\\ApplicationBlueprintServiceProvider', $emission->registrations[0]->fqcn);
        self::assertNull($emission->registrations[0]->group);
    }

    /**
     * Loads the generated provider through the real runtime: `roles()`
     * collected by the real `RoleRepository::fromProviders()`, and asserts
     * it actually implements `ProvidesRolesInterface`.
     */
    #[Test]
    public function theGeneratedProviderYieldsRealRolesCollectedByRoleRepository(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new GovernanceProviderEmitter()->emit($manifest->applicationBlueprint, $manifest);

        $namespace = 'Waaseyaa\\CLI\\Tests\\BlueprintGovernanceProvider' . bin2hex(random_bytes(4));
        $source = str_replace(
            ['namespace App\\Provider;', 'use App\\Workflow\\EditorialWorkflowDefinition;'],
            ['namespace ' . $namespace . ';', 'use ' . $namespace . '\\EditorialWorkflowDefinition;'],
            $emission->artifacts[0]->content,
        );

        $file = tempnam(sys_get_temp_dir(), 'waaseyaa_governance_provider_') . '.php';
        file_put_contents($file, $source);
        try {
            require $file;
            $class = $namespace . '\\ApplicationBlueprintGovernanceServiceProvider';
            $provider = new $class();
            self::assertInstanceOf(ProvidesRolesInterface::class, $provider);

            $repository = RoleRepository::fromProviders([$provider]);

            $editor = $repository->get('editor');
            self::assertNotNull($editor);
            self::assertSame('Editor', $editor->label);
            self::assertSame(['edit article', 'use editorial transition publish'], $editor->permissions);

            $viewer = $repository->get('viewer');
            self::assertNotNull($viewer);
            self::assertSame(['view article'], $viewer->permissions);
        } finally {
            unlink($file);
        }
    }

    /**
     * #2788 G1 closed: the generated provider contributes the generated
     * catalogue's `seed()` through the shared `ProvidesPermissionsInterface`
     * capability, so the kernel's boot-time catalogue knows every blueprint
     * permission and `RoleRepository::assertPermissionsCatalogued()` accepts
     * every generated role grant.
     */
    #[Test]
    public function theGeneratedProviderContributesTheGeneratedCatalogueSeedThroughTheSharedCapability(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new GovernanceProviderEmitter()->emit($manifest->applicationBlueprint, $manifest);
        $catalogueSource = new PermissionCatalogueEmitter()->emit($manifest->applicationBlueprint, $manifest)->artifacts[0]->content;

        $namespace = 'Waaseyaa\\CLI\\Tests\\BlueprintGovernanceCatalogue' . bin2hex(random_bytes(4));
        $providerSource = str_replace(
            ['namespace App\\Provider;', 'use App\\Workflow\\EditorialWorkflowDefinition;', 'App\\Access\\ApplicationBlueprintPermissions'],
            ['namespace ' . $namespace . ';', 'use ' . $namespace . '\\EditorialWorkflowDefinition;', $namespace . '\\ApplicationBlueprintPermissions'],
            $emission->artifacts[0]->content,
        );
        $catalogueSource = str_replace('namespace App\\Access;', 'namespace ' . $namespace . ';', $catalogueSource);

        $dir = sys_get_temp_dir() . '/waaseyaa_governance_catalogue_' . bin2hex(random_bytes(8));
        mkdir($dir, 0o700, true);
        file_put_contents($dir . '/ApplicationBlueprintPermissions.php', $catalogueSource);
        file_put_contents($dir . '/Provider.php', $providerSource);
        try {
            require $dir . '/ApplicationBlueprintPermissions.php';
            require $dir . '/Provider.php';
            $class = $namespace . '\\ApplicationBlueprintGovernanceServiceProvider';
            $provider = new $class();
            self::assertInstanceOf(ProvidesPermissionsInterface::class, $provider);

            $catalogueClass = $namespace . '\\ApplicationBlueprintPermissions';
            self::assertSame($catalogueClass::seed(), $provider->permissions());

            $catalogue = PermissionHandler::fromProviders([$provider]);
            self::assertSame(['edit article', 'use editorial transition publish', 'view article'], array_keys($catalogue->getPermissions()));
            RoleRepository::fromProviders([$provider])->assertPermissionsCatalogued($catalogue);
        } finally {
            new \Symfony\Component\Filesystem\Filesystem()->remove($dir);
        }
    }

    #[Test]
    public function aRoleIdOfAdministratorIsRefusedGen007BeforeAnyArtifactIsEmitted(): void
    {
        $manifest = $this->manifestWithBlueprint($this->blueprintWithOneRole(
            new BlueprintRole('administrator', 'Administrator', []),
        ));

        try {
            new GovernanceProviderEmitter()->emit($manifest->applicationBlueprint, $manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::UnsupportedDeclaration, $exception->violations[0]->code);
            self::assertStringContainsString('administrator', $exception->violations[0]->message);
        }
    }

    private function blueprintWithOneRole(BlueprintRole $role): ApplicationBlueprint
    {
        return new ApplicationBlueprint(
            contractVersion: 1,
            entities: [],
            relationships: [],
            permissions: [],
            roles: [$role->id => $role],
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
