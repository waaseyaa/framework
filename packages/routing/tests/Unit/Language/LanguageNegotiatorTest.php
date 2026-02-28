<?php

declare(strict_types=1);

namespace Aurora\Routing\Tests\Unit\Language;

use Aurora\I18n\Language;
use Aurora\I18n\LanguageContext;
use Aurora\I18n\LanguageManager;
use Aurora\Routing\Language\AcceptHeaderNegotiator;
use Aurora\Routing\Language\LanguageNegotiator;
use Aurora\Routing\Language\LanguageNegotiatorInterface;
use Aurora\Routing\Language\UrlPrefixNegotiator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LanguageNegotiator::class)]
#[CoversClass(UrlPrefixNegotiator::class)]
#[CoversClass(AcceptHeaderNegotiator::class)]
final class LanguageNegotiatorTest extends TestCase
{
    private Language $english;
    private Language $french;
    private Language $german;
    private LanguageManager $languageManager;

    /** @var string[] */
    private array $available;

    protected function setUp(): void
    {
        $this->english = new Language(id: 'en', label: 'English', isDefault: true);
        $this->french = new Language(id: 'fr', label: 'French');
        $this->german = new Language(id: 'de', label: 'German');

        $this->languageManager = new LanguageManager([
            $this->english,
            $this->french,
            $this->german,
        ]);

        $this->available = ['en', 'fr', 'de'];
    }

    // --- URL prefix strategy ---

    #[Test]
    public function urlPrefixResolvesLanguageFromFirstPathSegment(): void
    {
        $negotiator = new UrlPrefixNegotiator();

        $result = $negotiator->negotiate('/fr/content/about', [], $this->available);

        $this->assertSame('fr', $result);
    }

    #[Test]
    public function urlPrefixReturnsNullForUnknownPrefix(): void
    {
        $negotiator = new UrlPrefixNegotiator();

        $result = $negotiator->negotiate('/es/content/about', [], $this->available);

        $this->assertNull($result);
    }

    #[Test]
    public function urlPrefixReturnsNullForNoPrefix(): void
    {
        $negotiator = new UrlPrefixNegotiator();

        $result = $negotiator->negotiate('/content/about', [], $this->available);

        $this->assertNull($result);
    }

    #[Test]
    public function urlPrefixReturnsNullForRootPath(): void
    {
        $negotiator = new UrlPrefixNegotiator();

        $result = $negotiator->negotiate('/', [], $this->available);

        $this->assertNull($result);
    }

    #[Test]
    public function urlPrefixMatchesExactLanguageCode(): void
    {
        $negotiator = new UrlPrefixNegotiator();

        $result = $negotiator->negotiate('/de/page', [], $this->available);

        $this->assertSame('de', $result);
    }

    // --- Accept-Language header strategy ---

    #[Test]
    public function acceptHeaderResolvesFromSimpleHeader(): void
    {
        $negotiator = new AcceptHeaderNegotiator();

        $result = $negotiator->negotiate('/', ['accept-language' => 'fr'], $this->available);

        $this->assertSame('fr', $result);
    }

    #[Test]
    public function acceptHeaderResolvesHighestQuality(): void
    {
        $negotiator = new AcceptHeaderNegotiator();

        $result = $negotiator->negotiate(
            '/',
            ['accept-language' => 'fr-FR,fr;q=0.9,en;q=0.8'],
            $this->available,
        );

        // fr-FR is not available, but base "fr" is -- matched from fr-FR.
        $this->assertSame('fr', $result);
    }

    #[Test]
    public function acceptHeaderRespectsQualityOrder(): void
    {
        $negotiator = new AcceptHeaderNegotiator();

        $result = $negotiator->negotiate(
            '/',
            ['accept-language' => 'de;q=0.5,fr;q=0.9,en;q=0.8'],
            $this->available,
        );

        // fr has highest quality.
        $this->assertSame('fr', $result);
    }

    #[Test]
    public function acceptHeaderReturnsNullForMissingHeader(): void
    {
        $negotiator = new AcceptHeaderNegotiator();

        $result = $negotiator->negotiate('/', [], $this->available);

        $this->assertNull($result);
    }

    #[Test]
    public function acceptHeaderReturnsNullForNoMatch(): void
    {
        $negotiator = new AcceptHeaderNegotiator();

        $result = $negotiator->negotiate(
            '/',
            ['accept-language' => 'es,pt;q=0.9'],
            $this->available,
        );

        $this->assertNull($result);
    }

    #[Test]
    public function acceptHeaderMatchesBaseLanguageFromRegionalVariant(): void
    {
        $negotiator = new AcceptHeaderNegotiator();

        $result = $negotiator->negotiate(
            '/',
            ['accept-language' => 'en-US'],
            $this->available,
        );

        $this->assertSame('en', $result);
    }

    #[Test]
    public function acceptHeaderIgnoresWildcard(): void
    {
        $negotiator = new AcceptHeaderNegotiator();

        $result = $negotiator->negotiate(
            '/',
            ['accept-language' => '*,fr;q=0.5'],
            $this->available,
        );

        // Wildcard is skipped, "fr" matched next.
        $this->assertSame('fr', $result);
    }

    #[Test]
    public function acceptHeaderReturnsNullForEmptyHeader(): void
    {
        $negotiator = new AcceptHeaderNegotiator();

        $result = $negotiator->negotiate(
            '/',
            ['accept-language' => ''],
            $this->available,
        );

        $this->assertNull($result);
    }

    #[Test]
    public function acceptHeaderDefaultQualityIsOne(): void
    {
        $negotiator = new AcceptHeaderNegotiator();

        // "de" has implicit q=1.0, "fr" has q=0.9 -- de wins.
        $result = $negotiator->negotiate(
            '/',
            ['accept-language' => 'de,fr;q=0.9'],
            $this->available,
        );

        $this->assertSame('de', $result);
    }

    // --- Composite LanguageNegotiator ---

    #[Test]
    public function compositeUsesFirstMatchingStrategy(): void
    {
        $negotiator = new LanguageNegotiator(
            negotiators: [
                new UrlPrefixNegotiator(),
                new AcceptHeaderNegotiator(),
            ],
            languageManager: $this->languageManager,
        );

        $context = $negotiator->negotiate('/fr/content', ['accept-language' => 'de']);

        // URL prefix wins over Accept-Language.
        $this->assertSame('fr', $context->getContentLanguage()->id);
        $this->assertSame('fr', $context->getInterfaceLanguage()->id);
    }

    #[Test]
    public function compositeFallsToSecondStrategyWhenFirstFails(): void
    {
        $negotiator = new LanguageNegotiator(
            negotiators: [
                new UrlPrefixNegotiator(),
                new AcceptHeaderNegotiator(),
            ],
            languageManager: $this->languageManager,
        );

        $context = $negotiator->negotiate('/content/about', ['accept-language' => 'de']);

        // URL prefix didn't match, Accept-Language matched "de".
        $this->assertSame('de', $context->getContentLanguage()->id);
        $this->assertSame('de', $context->getInterfaceLanguage()->id);
    }

    #[Test]
    public function compositeFallsToDefaultWhenNoStrategyMatches(): void
    {
        $negotiator = new LanguageNegotiator(
            negotiators: [
                new UrlPrefixNegotiator(),
                new AcceptHeaderNegotiator(),
            ],
            languageManager: $this->languageManager,
        );

        $context = $negotiator->negotiate('/content/about', []);

        // No strategy matched, default language used.
        $this->assertSame('en', $context->getContentLanguage()->id);
        $this->assertSame('en', $context->getInterfaceLanguage()->id);
    }

    #[Test]
    public function compositeWorksWithEmptyNegotiatorList(): void
    {
        $negotiator = new LanguageNegotiator(
            negotiators: [],
            languageManager: $this->languageManager,
        );

        $context = $negotiator->negotiate('/fr/content');

        // No strategies, falls back to default.
        $this->assertSame('en', $context->getContentLanguage()->id);
    }

    #[Test]
    public function compositeReturnsLanguageContextInstance(): void
    {
        $negotiator = new LanguageNegotiator(
            negotiators: [new UrlPrefixNegotiator()],
            languageManager: $this->languageManager,
        );

        $context = $negotiator->negotiate('/de/page');

        $this->assertInstanceOf(LanguageContext::class, $context);
        $this->assertSame('de', $context->getContentLanguage()->id);
        $this->assertSame('German', $context->getContentLanguage()->label);
    }

    #[Test]
    public function compositeStrategyPriorityIsConfigurable(): void
    {
        // Accept-Language first, then URL prefix.
        $negotiator = new LanguageNegotiator(
            negotiators: [
                new AcceptHeaderNegotiator(),
                new UrlPrefixNegotiator(),
            ],
            languageManager: $this->languageManager,
        );

        $context = $negotiator->negotiate('/fr/content', ['accept-language' => 'de']);

        // Accept-Language wins because it's first in the list.
        $this->assertSame('de', $context->getContentLanguage()->id);
    }

    #[Test]
    public function compositeSkipsStrategiesThatReturnInvalidLanguage(): void
    {
        // Custom strategy that returns a langcode not in the language manager.
        $badStrategy = new class implements LanguageNegotiatorInterface {
            public function negotiate(string $pathInfo, array $headers, array $availableLanguages): ?string
            {
                return 'zz'; // Not a real language in the manager.
            }
        };

        $negotiator = new LanguageNegotiator(
            negotiators: [$badStrategy, new UrlPrefixNegotiator()],
            languageManager: $this->languageManager,
        );

        $context = $negotiator->negotiate('/fr/content');

        // "zz" is not in the language manager, so URL prefix strategy takes over.
        $this->assertSame('fr', $context->getContentLanguage()->id);
    }
}
