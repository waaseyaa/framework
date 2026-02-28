<?php

declare(strict_types=1);

namespace Aurora\Cache\Tests\Unit\Listener;

use Aurora\Cache\CacheTagsInvalidatorInterface;
use Aurora\Cache\Listener\ConfigCacheInvalidator;
use Aurora\Config\Event\ConfigEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigCacheInvalidator::class)]
final class ConfigCacheInvalidatorTest extends TestCase
{
    #[Test]
    public function on_post_save_invalidates_config_tags(): void
    {
        $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
        $invalidator->expects($this->once())
            ->method('invalidateTags')
            ->with(['config', 'config:system.site']);

        $listener = new ConfigCacheInvalidator($invalidator);
        $listener->onPostSave(new ConfigEvent('system.site', ['name' => 'Aurora']));
    }

    #[Test]
    public function on_post_delete_invalidates_config_tags(): void
    {
        $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
        $invalidator->expects($this->once())
            ->method('invalidateTags')
            ->with(['config', 'config:views.view.frontpage']);

        $listener = new ConfigCacheInvalidator($invalidator);
        $listener->onPostDelete(new ConfigEvent('views.view.frontpage'));
    }

    #[Test]
    public function invalidates_both_general_and_specific_tags(): void
    {
        $capturedTags = [];

        $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
        $invalidator->expects($this->once())
            ->method('invalidateTags')
            ->willReturnCallback(function (array $tags) use (&$capturedTags): void {
                $capturedTags = $tags;
            });

        $listener = new ConfigCacheInvalidator($invalidator);
        $listener->onPostSave(new ConfigEvent('node.type.article'));

        $this->assertContains('config', $capturedTags);
        $this->assertContains('config:node.type.article', $capturedTags);
    }
}
