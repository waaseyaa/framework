<?php

declare(strict_types=1);

namespace Aurora\Path\Tests\Unit;

use Aurora\Path\InMemoryPathAliasManager;
use Aurora\Path\PathAlias;
use Aurora\Path\PathProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathProcessor::class)]
final class PathProcessorTest extends TestCase
{
    private InMemoryPathAliasManager $manager;
    private PathProcessor $processor;

    protected function setUp(): void
    {
        $this->manager = new InMemoryPathAliasManager();
        $this->processor = new PathProcessor($this->manager);
    }

    private function addAlias(
        string $path,
        string $alias,
        string $langcode = 'en',
    ): void {
        $this->manager->addAlias(new PathAlias([
            'path' => $path,
            'alias' => $alias,
            'langcode' => $langcode,
            'status' => true,
        ]));
    }

    // --- processInbound ---

    #[Test]
    public function processInboundResolvesAliasToPath(): void
    {
        $this->addAlias('/node/42', '/about-us');

        $this->assertSame('/node/42', $this->processor->processInbound('/about-us'));
    }

    #[Test]
    public function processInboundPassesThroughWhenNoMatch(): void
    {
        $this->assertSame('/unknown', $this->processor->processInbound('/unknown'));
    }

    #[Test]
    public function processInboundRespectsLanguage(): void
    {
        $this->addAlias('/node/42', '/about-us', 'en');
        $this->addAlias('/node/99', '/about-us', 'fr');

        $this->assertSame('/node/42', $this->processor->processInbound('/about-us', 'en'));
        $this->assertSame('/node/99', $this->processor->processInbound('/about-us', 'fr'));
    }

    #[Test]
    public function processInboundPassesThroughSystemPaths(): void
    {
        $this->addAlias('/node/42', '/about-us');

        // A system path without a matching alias should pass through.
        $this->assertSame('/node/42', $this->processor->processInbound('/node/42'));
    }

    // --- processOutbound ---

    #[Test]
    public function processOutboundResolvesPathToAlias(): void
    {
        $this->addAlias('/node/42', '/about-us');

        $this->assertSame('/about-us', $this->processor->processOutbound('/node/42'));
    }

    #[Test]
    public function processOutboundPassesThroughWhenNoMatch(): void
    {
        $this->assertSame('/node/99', $this->processor->processOutbound('/node/99'));
    }

    #[Test]
    public function processOutboundRespectsLanguage(): void
    {
        $this->addAlias('/node/1', '/home', 'en');
        $this->addAlias('/node/1', '/accueil', 'fr');

        $this->assertSame('/home', $this->processor->processOutbound('/node/1', 'en'));
        $this->assertSame('/accueil', $this->processor->processOutbound('/node/1', 'fr'));
    }

    #[Test]
    public function processOutboundPassesThroughAlias(): void
    {
        $this->addAlias('/node/42', '/about-us');

        // An alias used as input should not find a matching system path.
        $this->assertSame('/about-us', $this->processor->processOutbound('/about-us'));
    }

    // --- Round-trip ---

    #[Test]
    public function roundTripInboundThenOutbound(): void
    {
        $this->addAlias('/node/42', '/about-us');

        // Inbound: alias -> system path
        $systemPath = $this->processor->processInbound('/about-us');
        $this->assertSame('/node/42', $systemPath);

        // Outbound: system path -> alias
        $aliasResult = $this->processor->processOutbound($systemPath);
        $this->assertSame('/about-us', $aliasResult);
    }

    #[Test]
    public function roundTripWithMultipleLanguages(): void
    {
        $this->addAlias('/node/1', '/home', 'en');
        $this->addAlias('/node/1', '/accueil', 'fr');

        // English round-trip
        $enPath = $this->processor->processInbound('/home', 'en');
        $this->assertSame('/node/1', $enPath);
        $this->assertSame('/home', $this->processor->processOutbound($enPath, 'en'));

        // French round-trip
        $frPath = $this->processor->processInbound('/accueil', 'fr');
        $this->assertSame('/node/1', $frPath);
        $this->assertSame('/accueil', $this->processor->processOutbound($frPath, 'fr'));
    }
}
