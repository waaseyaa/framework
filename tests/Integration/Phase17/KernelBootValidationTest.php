<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase17;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Foundation\Kernel\AbstractKernel;

/**
 * Validates the content-type guard in AbstractKernel::boot() via the real
 * kernel path. Each test writes a real `config/entity-types.php` under a
 * temp projectRoot, instantiates an anonymous subclass of AbstractKernel
 * exposing publicBoot(), and calls boot() — no MinimalTestKernel, no
 * partial boot(), no hand-wired EntityTypeManager.
 *
 * Bootstrap-variant policy: this file must use the anonymous-subclass +
 * real-projectRoot pattern codified in KernelBundleSubtableMaterializationTest.
 * See tests/Architecture/NoKernelSubclassesInTestsTest for enforcement.
 */
#[CoversClass(AbstractKernel::class)]
final class KernelBootValidationTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_boot_validation_' . uniqid();
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage/framework', 0o755, true);

        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );
    }

    protected function tearDown(): void
    {
        $registryProperty = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry');
        $registryProperty->setValue(null, null);

        new Filesystem()->remove($this->projectRoot);
    }

    private function writeEntityTypes(string $body): void
    {
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\nreturn {$body};\n");
    }

    private function newKernel(): AbstractKernel
    {
        return new class ($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };
    }

    #[Test]
    public function bootHaltsWithDefaultTypeMissingWhenNoTypesRegistered(): void
    {
        $this->writeEntityTypes('[]');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEFAULT_TYPE_MISSING/');

        $this->newKernel()->publicBoot();
    }

    #[Test]
    public function exceptionIncludesRemediationMessage(): void
    {
        $this->writeEntityTypes('[]');

        try {
            $this->newKernel()->publicBoot();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('DEFAULT_TYPE_MISSING', $e->getMessage());
            self::assertStringContainsString('content type', $e->getMessage());
        }
    }

    #[Test]
    public function bootSucceedsWithOneRegisteredContentType(): void
    {
        $this->writeEntityTypes(<<<'PHP'
            [
                new \Waaseyaa\Entity\EntityType(
                    id: 'note',
                    label: 'Note',
                    class: \Waaseyaa\Note\Note::class,
                    keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
                ),
            ]
            PHP);

        $kernel = $this->newKernel();
        $kernel->publicBoot();

        self::assertTrue($kernel->getEntityTypeManager()->hasDefinition('note'));
    }

    #[Test]
    public function bootSucceedsWithMultipleContentTypes(): void
    {
        $this->writeEntityTypes(<<<'PHP'
            [
                new \Waaseyaa\Entity\EntityType(id: 'note', label: 'Note', class: \Waaseyaa\Note\Note::class, keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title']),
                new \Waaseyaa\Entity\EntityType(id: 'article', label: 'Article', class: \Waaseyaa\Api\Tests\Fixtures\ArticleContentTestEntity::class, keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type']),
            ]
            PHP);

        $kernel = $this->newKernel();
        $kernel->publicBoot();

        self::assertCount(2, $kernel->getEntityTypeManager()->getDefinitions());
    }

    #[Test]
    public function bootHaltsWithDefaultTypeDisabledWhenAllTypesDisabled(): void
    {
        $this->writeEntityTypes(<<<'PHP'
            [
                new \Waaseyaa\Entity\EntityType(id: 'note', label: 'Note', class: \Waaseyaa\Note\Note::class, keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title']),
            ]
            PHP);

        new EntityTypeLifecycleManager($this->projectRoot)->disable('note', 'test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEFAULT_TYPE_DISABLED/');

        $this->newKernel()->publicBoot();
    }

    #[Test]
    public function bootSucceedsWhenOnlyOneOfTwoTypesIsDisabled(): void
    {
        $this->writeEntityTypes(<<<'PHP'
            [
                new \Waaseyaa\Entity\EntityType(id: 'note', label: 'Note', class: \Waaseyaa\Note\Note::class, keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title']),
                new \Waaseyaa\Entity\EntityType(id: 'article', label: 'Article', class: \Waaseyaa\Api\Tests\Fixtures\ArticleContentTestEntity::class, keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type']),
            ]
            PHP);

        new EntityTypeLifecycleManager($this->projectRoot)->disable('article', 'test');

        $kernel = $this->newKernel();
        $kernel->publicBoot();

        self::assertTrue($kernel->getEntityTypeManager()->hasDefinition('note'));
    }
}
