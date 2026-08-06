<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Hydration;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Hydration\FallbackChainResolver;
use Waaseyaa\Entity\TranslatableEntityTrait;

/**
 * Verifies that the WP06 additions to {@see TranslatableEntityTrait} consult
 * the configured fallback resolver during translatable-field reads (FR-037,
 * FR-038, FR-039) without altering behaviour for non-translatable types
 * (NFR-001 invariant).
 */
#[CoversTrait(TranslatableEntityTrait::class)]
#[CoversClass(FallbackChainResolver::class)]
final class TranslatableTraitFallbackTest extends TestCase
{
    private EntityTypeManager $manager;

    protected function setUp(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->manager = new EntityTypeManager($dispatcher);

        $this->manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: FallbackFixtureEntity::class,
            keys: ['id' => 'id', 'langcode' => 'langcode', 'default_langcode' => 'default_langcode'],
            translatable: true,
            _fieldDefinitions: [
                'title' => ['type' => 'string', 'translatable' => true],
                'slug' => ['type' => 'string', 'translatable' => false],
            ],
        ));

        $this->manager->registerEntityType(new EntityType(
            id: 'setting',
            label: 'Setting',
            class: NonTranslatableFixtureEntity::class,
            translatable: false,
        ));

        ContentEntityBase::setEntityTypeManager($this->manager);
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setEntityTypeManager(null);
    }

    // -------------------------------------------------------------------------
    // FR-037 / FR-038 — fallback walking
    // -------------------------------------------------------------------------

    #[Test]
    public function readsValueFromActiveLangcodeWhenPresent(): void
    {
        $entity = $this->makeEntity('en', [
            'en' => ['title' => 'English'],
            'fr' => ['title' => 'French'],
        ]);
        $entity->_setFallbackResolver(FallbackChainResolver::withDefaultChain('en'));

        $fr = $entity->getTranslation('fr');

        self::assertSame('French', $fr->get('title'));
        self::assertSame('fr', $fr->fieldLangcode('title'));
    }

    #[Test]
    public function walksChainWhenActiveLangcodeMissingValue(): void
    {
        $entity = $this->makeEntity('en', [
            'en' => ['title' => 'English title'],
            'fr' => ['title' => null],
        ]);
        $entity->_setFallbackResolver(FallbackChainResolver::withDefaultChain('en'));

        $fr = $entity->getTranslation('fr');

        self::assertSame('English title', $fr->get('title'));
        self::assertSame('en', $fr->fieldLangcode('title'));
    }

    #[Test]
    public function returnsNullAndCachesNullWhenChainExhausts(): void
    {
        $entity = $this->makeEntity('en', [
            'en' => ['title' => null],
            'fr' => ['title' => null],
        ]);
        $entity->_setFallbackResolver(FallbackChainResolver::withDefaultChain('en'));

        $fr = $entity->getTranslation('fr');

        self::assertNull($fr->get('title'));
        self::assertNull($fr->fieldLangcode('title'));
    }

    // -------------------------------------------------------------------------
    // FR-039 — repeat-read short-circuit
    // -------------------------------------------------------------------------

    #[Test]
    public function repeatReadShortCircuitsResolverInvocation(): void
    {
        $invocations = 0;
        $resolver = new FallbackChainResolver(
            static function (string $requested, EntityInterface $entity) use (&$invocations): array {
                ++$invocations;

                return ['fr', 'en'];
            },
        );

        $entity = $this->makeEntity('en', [
            'en' => ['title' => 'English title'],
            'fr' => ['title' => null],
        ]);
        $entity->_setFallbackResolver($resolver);
        $fr = $entity->getTranslation('fr');

        $first = $fr->get('title');
        $second = $fr->get('title');
        $third = $fr->get('title');

        self::assertSame('English title', $first);
        self::assertSame($first, $second);
        self::assertSame($first, $third);
        self::assertSame(1, $invocations, 'Resolver chain function must be invoked exactly once across repeat reads.');
        self::assertSame('en', $fr->fieldLangcode('title'));
    }

    #[Test]
    public function repeatReadOfExhaustedFieldAlsoShortCircuits(): void
    {
        $invocations = 0;
        $resolver = new FallbackChainResolver(
            static function (string $requested, EntityInterface $entity) use (&$invocations): array {
                ++$invocations;

                return ['fr', 'en'];
            },
        );

        $entity = $this->makeEntity('en', [
            'en' => ['title' => null],
            'fr' => ['title' => null],
        ]);
        $entity->_setFallbackResolver($resolver);
        $fr = $entity->getTranslation('fr');

        self::assertNull($fr->get('title'));
        self::assertNull($fr->get('title'));
        self::assertSame(1, $invocations, 'Resolver must not be re-walked once exhaustion is cached.');
    }

    // -------------------------------------------------------------------------
    // NFR-001 — non-translatable invariant
    // -------------------------------------------------------------------------

    #[Test]
    public function nonTranslatableEntityReadsAreUnchanged(): void
    {
        $entity = new NonTranslatableFixtureEntity(['title' => 'untouched']);

        self::assertSame('untouched', $entity->get('title'));
    }

    #[Test]
    public function nonTranslatableFieldOnTranslatableEntityBypassesChain(): void
    {
        $entity = $this->makeEntity('en', [
            'en' => ['slug' => 'english-slug'],
            'fr' => [],
        ]);
        $entity->set('slug', 'shared-slug');
        $entity->_setFallbackResolver(FallbackChainResolver::withDefaultChain('en'));

        $fr = $entity->getTranslation('fr');

        // Non-translatable field reads via parent::get() — returns the entity-level value.
        self::assertSame('shared-slug', $fr->get('slug'));
        // No fieldLangcode cache entry is created for non-translatable fields.
        self::assertNull($fr->fieldLangcode('slug'));
    }

    #[Test]
    public function withoutResolverReadsReturnActiveLangcodeValueDirectly(): void
    {
        $entity = $this->makeEntity('en', [
            'en' => ['title' => 'English title'],
            'fr' => ['title' => 'French title'],
        ]);
        // No _setFallbackResolver() call.

        $fr = $entity->getTranslation('fr');

        self::assertSame('French title', $fr->get('title'));
        self::assertSame('fr', $fr->fieldLangcode('title'));

        // Missing value at active langcode → null, no chain walked.
        $entity2 = $this->makeEntity('en', [
            'en' => ['title' => 'English title'],
            'fr' => ['title' => null],
        ]);
        $fr2 = $entity2->getTranslation('fr');

        self::assertNull($fr2->get('title'));
        self::assertNull($fr2->fieldLangcode('title'));
    }

    /**
     * @param array<string, array<string, mixed>> $translationData
     */
    private function makeEntity(string $defaultLc, array $translationData = []): FallbackFixtureEntity
    {
        $entity = new FallbackFixtureEntity(['default_langcode' => $defaultLc]);

        if ($translationData !== []) {
            $entity->_setTranslationData($translationData, $defaultLc);
        }

        return $entity;
    }
}

// ---------------------------------------------------------------------------
// Inline fixtures
// ---------------------------------------------------------------------------

final class FallbackFixtureEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'article', ['id' => 'id', 'uuid' => 'uuid']);
    }
}

final class NonTranslatableFixtureEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'setting', ['id' => 'id', 'uuid' => 'uuid']);
    }
}
