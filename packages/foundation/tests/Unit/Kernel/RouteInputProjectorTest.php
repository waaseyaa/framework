<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\EntityTypeApiExposurePolicy;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Kernel\RouteInputProjector;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionException;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteParticipationCompiler;
use Waaseyaa\Foundation\Routing\Metadata\ValidatedRouteParticipation;
use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

#[CoversClass(RouteInputProjector::class)]
final class RouteInputProjectorTest extends TestCase
{
    private function participation(): ValidatedRouteParticipation
    {
        $roster = [ProjectionNoopProvider::class, ProjectionPureProvider::class];
        return ValidatedRouteParticipation::atBootstrap($roster, new RouteParticipationCompiler()->compile($roster));
    }

    public function testProjectsExistingPolicyWithoutStorageServicesOrProviderExecution(): void
    {
        $definitions = [
            'post' => new EntityType(id: 'post', label: 'Post', class: \stdClass::class, bundleEntityType: 'post_type', api: true),
            'private' => new EntityType(id: 'private', label: 'Private', class: \stdClass::class, api: false),
        ];
        $policyManager = $this->createStub(EntityTypeManagerInterface::class);
        $policyManager->method('getDefinitions')->willReturn($definitions);
        $exposure = EntityTypeApiExposurePolicy::fromConfig($policyManager, ['api' => ['entity_type_allowlist' => []], 'secret' => 'private-value'])->effectiveMap();
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->expects(self::once())->method('getDefinitions')->willReturn($definitions);
        $manager->expects(self::never())->method('getStorage');
        $manager->expects(self::never())->method('getDefinition');
        $manager->expects(self::never())->method('getRepository');
        $manager->expects(self::never())->method('resolveFieldDefinitions');
        $projector = new RouteInputProjector();
        $inputs = $projector->project($manager, $exposure, ['api' => true]);
        $contexts = $projector->contexts($inputs, $this->participation());
        self::assertSame([ProjectionPureProvider::class], array_keys($contexts));
        $context = $contexts[ProjectionPureProvider::class];
        self::assertSame(1, $context->sourceOrder);
        self::assertSame([], $context->configuration);
        self::assertSame(['api' => true], $context->capabilities);
        self::assertSame([
            ['id' => 'post', 'bundle_entity_type' => 'post_type', 'api_exposed' => false],
            ['id' => 'private', 'bundle_entity_type' => null, 'api_exposed' => false],
        ], $context->entities);
        self::assertStringNotContainsString('private-value', json_encode($context->entities, JSON_THROW_ON_ERROR));
        $exposure['post'] = true;
        self::assertFalse($context->entities[0]['api_exposed']);
    }

    public function testRefusesPartialStaleAndNonBooleanExposureWithoutLeakingValues(): void
    {
        foreach ([[], ['other' => false], ['post' => 'private-value'], ['post' => true, 'other' => false]] as $exposure) {
            $manager = $this->createStub(EntityTypeManagerInterface::class);
            $manager->method('getDefinitions')->willReturn(['post' => new EntityType(id: 'post', label: 'Post', class: \stdClass::class)]);
            try {
                new RouteInputProjector()->project($manager, $exposure);
                self::fail('Incomplete or malformed exposure must refuse.');
            } catch (RouteCompositionException $error) {
                self::assertSame('inputs-unavailable', $error->reason);
                self::assertStringNotContainsString('private-value', $error->getMessage());
            }
        }
    }

    public function testRosterIdentityMismatchRefusesInsteadOfCoercingTheMap(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['wrong' => new EntityType(id: 'post', label: 'Post', class: \stdClass::class)]);
        $this->expectException(RouteCompositionException::class);
        new RouteInputProjector()->project($manager, ['wrong' => false]);
    }

    public function testProjectionNeverReadsExecutionOrFieldMetadataOrAutoloadsHandlers(): void
    {
        $definition = $this->createMock(EntityTypeInterface::class);
        $definition->expects(self::once())->method('id')->willReturn('post');
        $definition->expects(self::once())->method('getBundleEntityType')->willReturn(null);
        foreach (['getClass', 'getStorageClass', 'getFieldDefinitions', 'getKeys', 'getConstraints'] as $method) {
            $definition->expects(self::never())->method($method);
        }
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->expects(self::once())->method('getDefinitions')->willReturn(['post' => $definition]);
        $manager->expects(self::never())->method('getStorage');
        $manager->expects(self::never())->method('getRepository');
        $manager->expects(self::never())->method('resolveFieldDefinitions');
        $projector = new RouteInputProjector();
        // Metadata classes belong to bootstrap, before the measured projection boundary.
        new RouteContributionContext('bootstrap', 0);
        $autoloaded = [];
        $poison = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
            throw new \LogicException('Projection must not autoload.');
        };
        spl_autoload_register($poison, true, true);
        try {
            $inputs = $projector->project($manager, ['post' => false], ['api' => false]);
            self::assertSame([['id' => 'post', 'bundle_entity_type' => null, 'api_exposed' => false]], $inputs->entities);
        } finally {
            spl_autoload_unregister($poison);
        }
        self::assertSame([], $autoloaded);
    }

    public function testSharedProjectionSurvivesAllNoopCohortAndDetachesReferences(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['post' => new EntityType(id: 'post', label: 'Post', class: \stdClass::class)]);
        $exposed = false;
        $present = true;
        $projector = new RouteInputProjector();
        $inputs = $projector->project($manager, ['post' => &$exposed], ['api' => &$present]);
        $exposed = true;
        $present = false;
        $roster = [ProjectionNoopProvider::class];
        $participation = ValidatedRouteParticipation::atBootstrap($roster, new RouteParticipationCompiler()->compile($roster));
        self::assertSame([], $projector->contexts($inputs, $participation));
        self::assertFalse($inputs->entities[0]['api_exposed']);
        self::assertTrue($inputs->capabilities['api']);
    }

    public function testMetadataGetterFailureIsSanitizedAndPublishesNoProjection(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willThrowException(new \RuntimeException('private-value'));
        try {
            new RouteInputProjector()->project($manager, []);
            self::fail('Failed definition finalization must refuse.');
        } catch (RouteCompositionException $error) {
            self::assertSame('inputs-unavailable', $error->reason);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('private-value', $error->getMessage());
        }
    }

    public function testContextsRefuseForeignSharedInputSource(): void
    {
        $this->expectException(RouteCompositionException::class);
        new RouteInputProjector()->contexts(new RouteContributionContext('other', 0), $this->participation());
    }

    public function testContextsRefuseUnselectedConfigurationInsteadOfSilentlyDroppingIt(): void
    {
        $this->expectException(RouteCompositionException::class);
        new RouteInputProjector()->contexts(new RouteContributionContext('foundation.inputs', 0, ['secret' => 'private-value']), $this->participation());
    }

    public function testBundleMetadataRequiresAnIdentifier(): void
    {
        $definition = $this->createStub(EntityTypeInterface::class);
        $definition->method('id')->willReturn('post');
        $definition->method('getBundleEntityType')->willReturn('private value');
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['post' => $definition]);
        $this->expectException(RouteCompositionException::class);
        $this->expectExceptionMessage('Finalized route entity and exposure inputs are unavailable.');
        new RouteInputProjector()->project($manager, ['post' => false]);
    }

    public function testMatchingNumericLikeIdentifiersIgnoreMapInsertionOrder(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([
            '01' => new EntityType(id: '01', label: 'One', class: \stdClass::class),
            '1e0' => new EntityType(id: '1e0', label: 'Other One', class: \stdClass::class),
        ]);
        $inputs = new RouteInputProjector()->project($manager, ['1e0' => false, '01' => true]);
        self::assertSame(['01', '1e0'], array_column($inputs->entities, 'id'));
        self::assertSame([true, false], array_column($inputs->entities, 'api_exposed'));
    }

    public function testContextsRefuseNonzeroSharedSourceOrder(): void
    {
        $this->expectException(RouteCompositionException::class);
        new RouteInputProjector()->contexts(new RouteContributionContext('foundation.inputs', 1), $this->participation());
    }
}

class ProjectionNoopProvider extends ServiceProvider
{
    public function register(): void {}
}

final class ProjectionPureProvider extends ProjectionNoopProvider implements ContributesRouteMetadataInterface
{
    public function routeDefinitions(RouteContributionContext $context): iterable
    {
        throw new \LogicException('Projection must never invoke provider contributions.');
    }
}
