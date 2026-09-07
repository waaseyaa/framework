<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompiler;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(ApplicationBlueprintCompiler::class)]
final class CommunityEventsStarterCompilationTest extends TestCase
{
    protected function setUp(): void
    {
        EntityType::clearFromClassCache();
        EntityMetadataReader::clearCache();
    }

    #[Test]
    public function canonical_compiler_emits_the_domain_and_governance_plan(): void
    {
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($this->manifest());
        $paths = array_map(static fn (GeneratedArtifact $artifact): string => $artifact->path, $plan->artifacts);

        foreach ([
            'src/Entity/Event.php',
            'src/Entity/Organizer.php',
            'src/Entity/Venue.php',
            'src/Provider/ApplicationBlueprintGovernanceServiceProvider.php',
            'config/waaseyaa-blueprint/relationships.php',
            'src/Workflow/EventEditorialWorkflowDefinition.php',
            'tests/Blueprint/RolePermissionChecksTest.php',
            'tests/Blueprint/WorkflowTransitionChecksTest.php',
        ] as $path) {
            self::assertContains($path, $paths);
        }

        self::assertContains('tests/Blueprint/RolePermissionChecksTest.php', $plan->companionTests);
        self::assertContains('tests/Blueprint/WorkflowTransitionChecksTest.php', $plan->companionTests);
    }

    #[Test]
    public function registered_generated_event_has_public_content_and_only_the_engine_state_is_sealed(): void
    {
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($this->manifest());
        $source = $this->content($plan->artifacts, 'src/Entity/Event.php');
        $namespace = 'Waaseyaa\CLI\Tests\CommunityEvents' . bin2hex(random_bytes(4));
        $source = str_replace('namespace App\Entity;', 'namespace ' . $namespace . ';', $source);
        $file = tempnam(sys_get_temp_dir(), 'community_event_') . '.php';
        file_put_contents($file, $source);

        try {
            require $file;
            $class = $namespace . '\Event';
            $type = EntityType::fromClass($class, group: 'content', revisionable: true);
            $registry = new FieldDefinitionRegistry();
            $manager = new EntityTypeManager(
                $this->createStub(EventDispatcherInterface::class),
                fieldRegistry: $registry,
            );
            $manager->registerEntityType($type, self::class);

            $registered = $manager->getDefinition('event');
            self::assertSame($type, $registered);
            self::assertSame(FieldReadLevel::Public, $registry->coreFieldsFor('event')['title']->getReadLevel());
            self::assertSame(FieldReadLevel::Public, $registry->coreFieldsFor('event')['starts_at']->getReadLevel());
            self::assertSame(FieldReadLevel::Public, $registry->coreFieldsFor('event')['summary']->getReadLevel());
            self::assertSame(FieldReadLevel::Public, $registry->coreFieldsFor('event')['venue']->getReadLevel());
            self::assertSame(FieldReadLevel::Public, $registry->coreFieldsFor('event')['organizer']->getReadLevel());
            self::assertSame(FieldReadLevel::Protected, $registry->coreFieldsFor('event')['workflow_state']->getReadLevel());
        } finally {
            @unlink($file);
        }
    }

    private function manifest(): \Waaseyaa\SiteContract\SiteManifest
    {
        $path = dirname(__DIR__, 5).'/site-contract/resources/starters/community-events/v1.yaml';

        return new SiteManifestParser()->parse((string) file_get_contents($path), 'community-events@1');
    }

    /** @param list<GeneratedArtifact> $artifacts */
    private function content(array $artifacts, string $path): string
    {
        foreach ($artifacts as $artifact) {
            if ($artifact->path === $path) {
                return $artifact->content;
            }
        }

        self::fail("No artifact at {$path}");
    }
}
