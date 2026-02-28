<?php

declare(strict_types=1);

namespace Aurora\Cache\Tests\Unit\Listener;

use Aurora\Cache\CacheTagsInvalidatorInterface;
use Aurora\Cache\Listener\TranslationCacheInvalidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationCacheInvalidator::class)]
final class TranslationCacheInvalidatorTest extends TestCase
{
    #[Test]
    public function invalidate_all_sends_translations_tag(): void
    {
        $tagsInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
        $tagsInvalidator->expects($this->once())
            ->method('invalidateTags')
            ->with(['translations']);

        $invalidator = new TranslationCacheInvalidator($tagsInvalidator);
        $invalidator->invalidateAll();
    }

    #[Test]
    public function invalidate_language_sends_language_specific_tag(): void
    {
        $tagsInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
        $tagsInvalidator->expects($this->once())
            ->method('invalidateTags')
            ->with(['translations', 'translations:fr']);

        $invalidator = new TranslationCacheInvalidator($tagsInvalidator);
        $invalidator->invalidateLanguage('fr');
    }

    #[Test]
    public function invalidate_context_sends_context_specific_tag(): void
    {
        $tagsInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
        $tagsInvalidator->expects($this->once())
            ->method('invalidateTags')
            ->with(['translations', 'translations:context:admin']);

        $invalidator = new TranslationCacheInvalidator($tagsInvalidator);
        $invalidator->invalidateContext('admin');
    }
}
