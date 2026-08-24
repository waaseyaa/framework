<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Support;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Api\Tests\Fixtures\NodeContentTestEntity;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Tests\Support\ComposerProjectFixture;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/**
 * Regression for the ci/random-order failure "Conflicting field-read
 * definitions for node.status": RuntimeSchemaMigrations::entitiesForProject()
 * boots a throwaway restricted kernel whose FieldDefinitionRegistry (carrying
 * the real node.status Protected/authorizationInput classification) was left
 * installed process-wide. Any later test in the same process that hydrates a
 * fixture `node` entity type declaring `status` Public — without passing its
 * own registry — merged the leaked kernel registry into its layout and threw.
 */
#[CoversNothing]
final class RuntimeSchemaMigrationsFieldRegistryResetTest extends TestCase
{
    private string $projectRoot = '';

    protected function setUp(): void
    {
        $repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_schema_sync_isolation_' . uniqid();

        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);

        ComposerProjectFixture::installMetadata($repoRoot, $this->projectRoot);

        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\n\nreturn [];\n");
        $databasePath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', <<<PHP
            <?php

            declare(strict_types=1);

            return [
                'database' => '{$databasePath}',
                'environment' => 'testing',
                'app' => ['url' => 'http://localhost', 'name' => 'Waaseyaa Schema Sync Isolation Test'],
            ];
            PHP);

        // Hermetic baseline: an earlier test in the process may itself have
        // leaked a registry; this test proves entitiesForProject() adds none.
        ContentEntityBase::setFieldRegistry(null);
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);

        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            return;
        }
        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    public function entitiesForProjectLeavesNoProcessWideFieldRegistry(): void
    {
        RuntimeSchemaMigrations::entitiesForProject($this->projectRoot);

        $entityRuntimeRegistry = new \ReflectionProperty(EntityReadRuntime::class, 'fieldRegistry')->getValue();
        $contentEntityRegistry = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry')->getValue();

        self::assertNull(
            $entityRuntimeRegistry,
            'entitiesForProject() must not leave its throwaway kernel registry installed on EntityReadRuntime — '
            . 'it becomes the layoutFor() fallback for every later test in the process.',
        );
        self::assertNull(
            $contentEntityRegistry,
            'entitiesForProject() must not leave its throwaway kernel registry installed on ContentEntityBase.',
        );
    }

    #[Test]
    public function fixtureNodeTypeWithPublicStatusHydratesAfterSchemaSync(): void
    {
        RuntimeSchemaMigrations::entitiesForProject($this->projectRoot);

        // The exact victim shape from Phase14 DiscoveryFixtureConsumersIntegrationTest:
        // a fixture `node` entity type declaring `status` Public, hydrated with no
        // explicit registry so layoutFor() falls back to the process-wide one.
        // With the leaked kernel registry (node.status Protected/authorizationInput)
        // this threw "Conflicting field-read definitions for node.status."
        $entityType = new EntityType(
            id: 'node',
            label: 'Node',
            class: NodeContentTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            _fieldDefinitions: [
                'title' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'status' => ['type' => 'boolean', 'read' => FieldReadLevel::Public],
            ],
        );

        $layout = EntityReadRuntime::layoutFor(
            NodeContentTestEntity::class,
            ['id' => 1, 'type' => 'article', 'title' => 'Water Teaching Anchor', 'status' => true],
            'node',
            $entityType->getKeys(),
            null,
            true,
            $entityType->getFieldDefinitions(),
        );

        self::assertSame(FieldReadLevel::Public, $layout->levels()['status'] ?? null);
    }
}
