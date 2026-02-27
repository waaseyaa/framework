<?php

declare(strict_types=1);

namespace Aurora\Path\Tests\Unit;

use Aurora\Path\InMemoryPathAliasManager;
use Aurora\Path\PathAlias;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryPathAliasManager::class)]
final class InMemoryPathAliasManagerTest extends TestCase
{
    private InMemoryPathAliasManager $manager;

    protected function setUp(): void
    {
        $this->manager = new InMemoryPathAliasManager();
    }

    private function createAlias(
        string $path,
        string $alias,
        string $langcode = 'en',
        bool $published = true,
    ): PathAlias {
        return new PathAlias([
            'path' => $path,
            'alias' => $alias,
            'langcode' => $langcode,
            'status' => $published,
        ]);
    }

    // --- getAliasByPath ---

    #[Test]
    public function getAliasByPathReturnsAliasWhenFound(): void
    {
        $this->manager->addAlias($this->createAlias('/node/42', '/about-us'));

        $this->assertSame('/about-us', $this->manager->getAliasByPath('/node/42'));
    }

    #[Test]
    public function getAliasByPathReturnsOriginalPathWhenNotFound(): void
    {
        $this->assertSame('/node/99', $this->manager->getAliasByPath('/node/99'));
    }

    #[Test]
    public function getAliasByPathReturnsOriginalPathWhenNoAliasesExist(): void
    {
        $this->assertSame('/node/1', $this->manager->getAliasByPath('/node/1'));
    }

    #[Test]
    public function getAliasByPathMatchesLanguage(): void
    {
        $this->manager->addAlias($this->createAlias('/node/42', '/about-us', 'en'));
        $this->manager->addAlias($this->createAlias('/node/42', '/a-propos', 'fr'));

        $this->assertSame('/about-us', $this->manager->getAliasByPath('/node/42', 'en'));
        $this->assertSame('/a-propos', $this->manager->getAliasByPath('/node/42', 'fr'));
    }

    #[Test]
    public function getAliasByPathReturnsOriginalPathForNonMatchingLanguage(): void
    {
        $this->manager->addAlias($this->createAlias('/node/42', '/about-us', 'en'));

        $this->assertSame('/node/42', $this->manager->getAliasByPath('/node/42', 'de'));
    }

    #[Test]
    public function getAliasByPathIgnoresUnpublishedAliases(): void
    {
        $this->manager->addAlias($this->createAlias('/node/42', '/about-us', 'en', false));

        $this->assertSame('/node/42', $this->manager->getAliasByPath('/node/42'));
    }

    #[Test]
    public function getAliasByPathReturnsFirstMatchingAlias(): void
    {
        $this->manager->addAlias($this->createAlias('/node/42', '/about', 'en'));
        $this->manager->addAlias($this->createAlias('/node/42', '/about-us', 'en'));

        $this->assertSame('/about', $this->manager->getAliasByPath('/node/42'));
    }

    // --- getPathByAlias ---

    #[Test]
    public function getPathByAliasReturnsPathWhenFound(): void
    {
        $this->manager->addAlias($this->createAlias('/node/42', '/about-us'));

        $this->assertSame('/node/42', $this->manager->getPathByAlias('/about-us'));
    }

    #[Test]
    public function getPathByAliasReturnsOriginalAliasWhenNotFound(): void
    {
        $this->assertSame('/unknown', $this->manager->getPathByAlias('/unknown'));
    }

    #[Test]
    public function getPathByAliasMatchesLanguage(): void
    {
        $this->manager->addAlias($this->createAlias('/node/42', '/about-us', 'en'));
        $this->manager->addAlias($this->createAlias('/node/99', '/about-us', 'fr'));

        $this->assertSame('/node/42', $this->manager->getPathByAlias('/about-us', 'en'));
        $this->assertSame('/node/99', $this->manager->getPathByAlias('/about-us', 'fr'));
    }

    #[Test]
    public function getPathByAliasReturnsOriginalForNonMatchingLanguage(): void
    {
        $this->manager->addAlias($this->createAlias('/node/42', '/about-us', 'en'));

        $this->assertSame('/about-us', $this->manager->getPathByAlias('/about-us', 'de'));
    }

    #[Test]
    public function getPathByAliasIgnoresUnpublishedAliases(): void
    {
        $this->manager->addAlias($this->createAlias('/node/42', '/about-us', 'en', false));

        $this->assertSame('/about-us', $this->manager->getPathByAlias('/about-us'));
    }

    // --- aliasExists ---

    #[Test]
    public function aliasExistsReturnsTrueWhenAliasExists(): void
    {
        $this->manager->addAlias($this->createAlias('/node/42', '/about-us'));

        $this->assertTrue($this->manager->aliasExists('/about-us'));
    }

    #[Test]
    public function aliasExistsReturnsFalseWhenAliasDoesNotExist(): void
    {
        $this->assertFalse($this->manager->aliasExists('/nonexistent'));
    }

    #[Test]
    public function aliasExistsMatchesLanguage(): void
    {
        $this->manager->addAlias($this->createAlias('/node/42', '/about-us', 'en'));

        $this->assertTrue($this->manager->aliasExists('/about-us', 'en'));
        $this->assertFalse($this->manager->aliasExists('/about-us', 'fr'));
    }

    #[Test]
    public function aliasExistsIgnoresUnpublishedAliases(): void
    {
        $this->manager->addAlias($this->createAlias('/node/42', '/about-us', 'en', false));

        $this->assertFalse($this->manager->aliasExists('/about-us'));
    }

    // --- Multiple aliases scenario ---

    #[Test]
    public function multipleAliasesForDifferentPaths(): void
    {
        $this->manager->addAlias($this->createAlias('/node/1', '/home'));
        $this->manager->addAlias($this->createAlias('/node/2', '/about'));
        $this->manager->addAlias($this->createAlias('/node/3', '/contact'));

        $this->assertSame('/home', $this->manager->getAliasByPath('/node/1'));
        $this->assertSame('/about', $this->manager->getAliasByPath('/node/2'));
        $this->assertSame('/contact', $this->manager->getAliasByPath('/node/3'));

        $this->assertSame('/node/1', $this->manager->getPathByAlias('/home'));
        $this->assertSame('/node/2', $this->manager->getPathByAlias('/about'));
        $this->assertSame('/node/3', $this->manager->getPathByAlias('/contact'));
    }

    #[Test]
    public function mixedPublishedAndUnpublishedAliases(): void
    {
        $this->manager->addAlias($this->createAlias('/node/1', '/draft-page', 'en', false));
        $this->manager->addAlias($this->createAlias('/node/2', '/published-page', 'en', true));

        $this->assertSame('/node/1', $this->manager->getAliasByPath('/node/1'));
        $this->assertSame('/published-page', $this->manager->getAliasByPath('/node/2'));
        $this->assertFalse($this->manager->aliasExists('/draft-page'));
        $this->assertTrue($this->manager->aliasExists('/published-page'));
    }

    #[Test]
    public function multilingualAliasLookup(): void
    {
        $this->manager->addAlias($this->createAlias('/node/1', '/home', 'en'));
        $this->manager->addAlias($this->createAlias('/node/1', '/accueil', 'fr'));
        $this->manager->addAlias($this->createAlias('/node/1', '/startseite', 'de'));

        $this->assertSame('/home', $this->manager->getAliasByPath('/node/1', 'en'));
        $this->assertSame('/accueil', $this->manager->getAliasByPath('/node/1', 'fr'));
        $this->assertSame('/startseite', $this->manager->getAliasByPath('/node/1', 'de'));
        $this->assertSame('/node/1', $this->manager->getAliasByPath('/node/1', 'ja'));

        $this->assertTrue($this->manager->aliasExists('/home', 'en'));
        $this->assertTrue($this->manager->aliasExists('/accueil', 'fr'));
        $this->assertFalse($this->manager->aliasExists('/home', 'fr'));
    }
}
