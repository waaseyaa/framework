<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase17;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Note\Note;

#[CoversClass(AbstractKernel::class)]
final class KernelBootValidationTest extends TestCase
{
    #[Test]
    public function bootHaltsWithDefaultTypeMissingWhenNoTypesRegistered(): void
    {
        $kernel = new MinimalTestKernel(types: []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEFAULT_TYPE_MISSING/');

        $kernel->bootForTest();
    }

    #[Test]
    public function exceptionIncludesRemediationMessage(): void
    {
        $kernel = new MinimalTestKernel(types: []);

        try {
            $kernel->bootForTest();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('DEFAULT_TYPE_MISSING', $e->getMessage());
            $this->assertStringContainsString('content type', $e->getMessage());
        }
    }

    #[Test]
    public function bootSucceedsWithOneRegisteredContentType(): void
    {
        $kernel = new MinimalTestKernel(types: [
            new EntityType(
                id: 'note',
                label: 'Note',
                class: Note::class,
                keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            ),
        ]);

        // Should not throw.
        $kernel->bootForTest();

        $this->assertTrue($kernel->getEntityTypeManager()->hasDefinition('note'));
    }

    #[Test]
    public function bootSucceedsWithMultipleContentTypes(): void
    {
        $kernel = new MinimalTestKernel(types: [
            new EntityType(id: 'note', label: 'Note', class: Note::class, keys: ['id' => 'id']),
            new EntityType(id: 'article', label: 'Article', class: Note::class, keys: ['id' => 'id']),
        ]);

        $kernel->bootForTest();

        $this->assertCount(2, $kernel->getEntityTypeManager()->getDefinitions());
    }

    #[Test]
    public function bootHaltsWithDefaultTypeDisabledWhenAllTypesDisabled(): void
    {
        $tempDir = sys_get_temp_dir() . '/waaseyaa_disabled_test_' . uniqid();
        mkdir($tempDir . '/storage/framework', 0755, true);

        $lifecycleManager = new EntityTypeLifecycleManager($tempDir);
        $lifecycleManager->disable('note', 'test');

        $kernel = new MinimalTestKernel(types: [
            new EntityType(id: 'note', label: 'Note', class: Note::class, keys: ['id' => 'id']),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEFAULT_TYPE_DISABLED/');

        try {
            $kernel->bootForTest($lifecycleManager);
        } finally {
            array_map('unlink', glob($tempDir . '/storage/framework/*') ?: []);
            @rmdir($tempDir . '/storage/framework');
            @rmdir($tempDir . '/storage');
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function bootSucceedsWhenOnlyOneOfTwoTypesIsDisabled(): void
    {
        $tempDir = sys_get_temp_dir() . '/waaseyaa_partial_disabled_test_' . uniqid();
        mkdir($tempDir . '/storage/framework', 0755, true);

        $lifecycleManager = new EntityTypeLifecycleManager($tempDir);
        $lifecycleManager->disable('article', 'test');

        $kernel = new MinimalTestKernel(types: [
            new EntityType(id: 'note', label: 'Note', class: Note::class, keys: ['id' => 'id']),
            new EntityType(id: 'article', label: 'Article', class: Note::class, keys: ['id' => 'id']),
        ]);

        // Should not throw — 'note' is still enabled.
        $kernel->bootForTest($lifecycleManager);

        array_map('unlink', glob($tempDir . '/storage/framework/*') ?: []);
        @rmdir($tempDir . '/storage/framework');
        @rmdir($tempDir . '/storage');
        @rmdir($tempDir);

        $this->assertTrue(true); // boot succeeded
    }
}

/**
 * Minimal AbstractKernel subclass for testing boot validation in isolation.
 *
 * Skips database, manifest, providers, access policies, and extensions.
 * Only exercises the entity type registration + content type validation path.
 */
class MinimalTestKernel extends AbstractKernel
{
    /** @param \Waaseyaa\Entity\EntityTypeInterface[] $types */
    public function __construct(
        private readonly array $types,
    ) {
        parent::__construct(projectRoot: sys_get_temp_dir());
    }

    /**
     * Runs only the entity type registration + validation portion of boot.
     */
    public function bootForTest(?EntityTypeLifecycleManager $lifecycleManager = null): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->entityTypeManager = new EntityTypeManager($this->dispatcher);
        $this->lifecycleManager = $lifecycleManager ?? new EntityTypeLifecycleManager(sys_get_temp_dir());

        foreach ($this->types as $type) {
            $this->entityTypeManager->registerEntityType($type);
        }

        $this->validateContentTypes();
    }
}
