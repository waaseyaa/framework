<?php

declare(strict_types=1);

namespace Aurora\Path\Tests\Unit;

use Aurora\Path\PathAlias;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathAlias::class)]
final class PathAliasTest extends TestCase
{
    #[Test]
    public function constructorSetsEntityTypeId(): void
    {
        $alias = new PathAlias();

        $this->assertSame('path_alias', $alias->getEntityTypeId());
    }

    #[Test]
    public function constructorSetsDefaultLanguage(): void
    {
        $alias = new PathAlias();

        $this->assertSame('en', $alias->getLanguage());
    }

    #[Test]
    public function constructorSetsDefaultPublishedStatus(): void
    {
        $alias = new PathAlias();

        $this->assertTrue($alias->isPublished());
    }

    #[Test]
    public function constructorAutoGeneratesUuid(): void
    {
        $alias = new PathAlias();

        $this->assertNotEmpty($alias->uuid());
    }

    #[Test]
    public function constructorAcceptsInitialValues(): void
    {
        $alias = new PathAlias([
            'id' => 1,
            'path' => '/node/42',
            'alias' => '/about-us',
            'langcode' => 'fr',
            'status' => false,
        ]);

        $this->assertSame(1, $alias->id());
        $this->assertSame('/node/42', $alias->getPath());
        $this->assertSame('/about-us', $alias->getAlias());
        $this->assertSame('fr', $alias->getLanguage());
        $this->assertFalse($alias->isPublished());
    }

    #[Test]
    public function getPathReturnsEmptyStringByDefault(): void
    {
        $alias = new PathAlias();

        $this->assertSame('', $alias->getPath());
    }

    #[Test]
    public function setPathUpdatesPath(): void
    {
        $alias = new PathAlias();
        $result = $alias->setPath('/node/42');

        $this->assertSame('/node/42', $alias->getPath());
        $this->assertSame($alias, $result, 'setPath should return $this for fluent chaining');
    }

    #[Test]
    public function getAliasReturnsEmptyStringByDefault(): void
    {
        $alias = new PathAlias();

        $this->assertSame('', $alias->getAlias());
    }

    #[Test]
    public function setAliasUpdatesAlias(): void
    {
        $alias = new PathAlias();
        $result = $alias->setAlias('/about-us');

        $this->assertSame('/about-us', $alias->getAlias());
        $this->assertSame($alias, $result, 'setAlias should return $this for fluent chaining');
    }

    #[Test]
    public function setAliasValidatesLeadingSlash(): void
    {
        $alias = new PathAlias();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with a forward slash');

        $alias->setAlias('about-us');
    }

    #[Test]
    public function setAliasAllowsEmptyString(): void
    {
        $alias = new PathAlias(['alias' => '/about-us']);
        $alias->setAlias('');

        $this->assertSame('', $alias->getAlias());
    }

    #[Test]
    public function getLanguageReturnsDefaultEnglish(): void
    {
        $alias = new PathAlias();

        $this->assertSame('en', $alias->getLanguage());
    }

    #[Test]
    public function setLanguageUpdatesLanguage(): void
    {
        $alias = new PathAlias();
        $result = $alias->setLanguage('de');

        $this->assertSame('de', $alias->getLanguage());
        $this->assertSame($alias, $result, 'setLanguage should return $this for fluent chaining');
    }

    #[Test]
    public function isPublishedReturnsTrueByDefault(): void
    {
        $alias = new PathAlias();

        $this->assertTrue($alias->isPublished());
    }

    #[Test]
    public function setPublishedUpdatesStatus(): void
    {
        $alias = new PathAlias();
        $result = $alias->setPublished(false);

        $this->assertFalse($alias->isPublished());
        $this->assertSame($alias, $result, 'setPublished should return $this for fluent chaining');
    }

    #[Test]
    public function setPublishedCanReEnable(): void
    {
        $alias = new PathAlias(['status' => false]);
        $this->assertFalse($alias->isPublished());

        $alias->setPublished(true);
        $this->assertTrue($alias->isPublished());
    }

    #[Test]
    public function labelReturnsAlias(): void
    {
        $alias = new PathAlias(['alias' => '/about-us']);

        $this->assertSame('/about-us', $alias->label());
    }

    #[Test]
    public function toArrayReturnsAllValues(): void
    {
        $alias = new PathAlias([
            'id' => 1,
            'path' => '/node/42',
            'alias' => '/about-us',
            'langcode' => 'en',
            'status' => true,
        ]);

        $array = $alias->toArray();

        $this->assertSame(1, $array['id']);
        $this->assertSame('/node/42', $array['path']);
        $this->assertSame('/about-us', $array['alias']);
        $this->assertSame('en', $array['langcode']);
        $this->assertTrue($array['status']);
        $this->assertArrayHasKey('uuid', $array);
    }

    #[Test]
    public function isNewWhenNoIdSet(): void
    {
        $alias = new PathAlias();

        $this->assertTrue($alias->isNew());
    }

    #[Test]
    public function isNotNewWhenIdIsSet(): void
    {
        $alias = new PathAlias(['id' => 5]);

        $this->assertFalse($alias->isNew());
    }

    #[Test]
    public function bundleReturnsEntityTypeId(): void
    {
        $alias = new PathAlias();

        $this->assertSame('path_alias', $alias->bundle());
    }

    #[Test]
    public function fluentChaining(): void
    {
        $alias = new PathAlias();

        $result = $alias
            ->setPath('/node/1')
            ->setAlias('/home')
            ->setLanguage('fr')
            ->setPublished(true);

        $this->assertSame('/node/1', $result->getPath());
        $this->assertSame('/home', $result->getAlias());
        $this->assertSame('fr', $result->getLanguage());
        $this->assertTrue($result->isPublished());
    }

    #[Test]
    public function hasFieldReturnsTrueForKnownFields(): void
    {
        $alias = new PathAlias([
            'path' => '/node/42',
            'alias' => '/about-us',
        ]);

        $this->assertTrue($alias->hasField('path'));
        $this->assertTrue($alias->hasField('alias'));
        $this->assertTrue($alias->hasField('langcode'));
        $this->assertTrue($alias->hasField('status'));
    }

    #[Test]
    public function hasFieldReturnsFalseForUnknownFields(): void
    {
        $alias = new PathAlias();

        $this->assertFalse($alias->hasField('nonexistent'));
    }

    #[Test]
    public function languageMethodFromEntityBase(): void
    {
        $alias = new PathAlias(['langcode' => 'ja']);

        $this->assertSame('ja', $alias->language());
    }
}
