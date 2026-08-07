<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Exception\EntityTranslationException;
use Waaseyaa\Entity\TranslatableEntityTrait;
use Waaseyaa\Entity\TranslatableInterface;

#[CoversTrait(TranslatableEntityTrait::class)]
final class TranslatableEntityTraitTest extends TestCase
{
    private EntityTypeManager $manager;

    protected function setUp(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->manager = new EntityTypeManager($dispatcher);

        // Register a translatable entity type.
        // T007 (WP02): translatable:true requires langcode + default_langcode keys.
        $this->manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: FixtureTranslatableEntity::class,
            keys: ['id' => 'id', 'langcode' => 'langcode', 'default_langcode' => 'default_langcode'],
            translatable: true,
        ));

        // Register a non-translatable entity type.
        $this->manager->registerEntityType(new EntityType(
            id: 'setting',
            label: 'Setting',
            class: FixtureNonTranslatableEntity::class,
            translatable: false,
        ));

        ContentEntityBase::setEntityTypeManager($this->manager);
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setEntityTypeManager(null);
    }

    // -------------------------------------------------------------------------
    // defaultLangcode()
    // -------------------------------------------------------------------------

    #[Test]
    public function defaultLangcodeReturnsValueFromValues(): void
    {
        $entity = $this->makeTranslatable('en');

        self::assertSame('en', $entity->defaultLangcode());
    }

    #[Test]
    public function defaultLangcodeUsesTheSealedStructuralDefaultWhenUnset(): void
    {
        $entity = new FixtureTranslatableEntity([]);

        self::assertSame('en', $entity->defaultLangcode());
    }

    // -------------------------------------------------------------------------
    // activeLangcode()
    // -------------------------------------------------------------------------

    #[Test]
    public function activeLangcodeDefaultsToDefaultLangcode(): void
    {
        $entity = $this->makeTranslatable('en');

        self::assertSame('en', $entity->activeLangcode());
    }

    // -------------------------------------------------------------------------
    // hasTranslation()
    // -------------------------------------------------------------------------

    #[Test]
    public function hasTranslationReturnsTrueForKnownLangcode(): void
    {
        $entity = $this->makeTranslatable('en', ['en' => [], 'fr' => []]);

        self::assertTrue($entity->hasTranslation('fr'));
    }

    #[Test]
    public function hasTranslationReturnsFalseForUnknownLangcode(): void
    {
        $entity = $this->makeTranslatable('en', ['en' => []]);

        self::assertFalse($entity->hasTranslation('de'));
    }

    // -------------------------------------------------------------------------
    // getTranslation()
    // -------------------------------------------------------------------------

    #[Test]
    public function getTranslationReturnsCloneWithCorrectActiveLangcode(): void
    {
        $entity = $this->makeTranslatable('en', ['en' => [], 'fr' => []]);

        $fr = $entity->getTranslation('fr');

        self::assertNotSame($entity, $fr);
        self::assertSame('fr', $fr->activeLangcode());
        self::assertSame('en', $entity->activeLangcode());
    }

    #[Test]
    public function getTranslationReturnsSelfForActiveLangcode(): void
    {
        $entity = $this->makeTranslatable('en', ['en' => []]);

        self::assertSame($entity, $entity->getTranslation('en'));
    }

    #[Test]
    public function getTranslationThrowsForMissingLangcode(): void
    {
        $entity = $this->makeTranslatable('en', ['en' => []]);

        $this->expectException(EntityTranslationException::class);
        $this->expectExceptionMessage('fr');

        $entity->getTranslation('fr');
    }

    // -------------------------------------------------------------------------
    // addTranslation()
    // -------------------------------------------------------------------------

    #[Test]
    public function addTranslationReturnsCloneActiveInNewLangcode(): void
    {
        $entity = $this->makeTranslatable('en', ['en' => []]);

        $fr = $entity->addTranslation('fr');

        self::assertNotSame($entity, $fr);
        self::assertSame('fr', $fr->activeLangcode());
        self::assertTrue($entity->hasTranslation('fr'));
    }

    #[Test]
    public function addTranslationThrowsWhenAlreadyExists(): void
    {
        $entity = $this->makeTranslatable('en', ['en' => [], 'fr' => []]);

        $this->expectException(EntityTranslationException::class);
        $this->expectExceptionMessage('fr');

        $entity->addTranslation('fr');
    }

    // -------------------------------------------------------------------------
    // removeTranslation()
    // -------------------------------------------------------------------------

    #[Test]
    public function removeTranslationAddsLangcodeToPendingDeletions(): void
    {
        $entity = $this->makeTranslatable('en', ['en' => [], 'fr' => []]);

        $entity->removeTranslation('fr');

        $deletions = $entity->_takePendingTranslationDeletions();
        self::assertContains('fr', $deletions);
        self::assertFalse($entity->hasTranslation('fr'));
    }

    #[Test]
    public function removeTranslationThrowsForDefaultLangcode(): void
    {
        $entity = $this->makeTranslatable('en', ['en' => []]);

        $this->expectException(EntityTranslationException::class);
        $this->expectExceptionMessage('en');

        $entity->removeTranslation('en');
    }

    #[Test]
    public function takePendingTranslationDeletionsClearsTheList(): void
    {
        $entity = $this->makeTranslatable('en', ['en' => [], 'fr' => []]);
        $entity->removeTranslation('fr');

        $first = $entity->_takePendingTranslationDeletions();
        $second = $entity->_takePendingTranslationDeletions();

        self::assertNotEmpty($first);
        self::assertEmpty($second);
    }

    // -------------------------------------------------------------------------
    // translations()
    // -------------------------------------------------------------------------

    #[Test]
    public function translationsYieldsDefaultFirstThenAscending(): void
    {
        $entity = $this->makeTranslatable('en', ['de' => [], 'en' => [], 'fr' => []]);

        $langcodes = \iterator_to_array($entity->translations(), false);

        self::assertSame('en', $langcodes[0], 'default langcode must come first');
        self::assertSame(['de', 'fr'], \array_slice($langcodes, 1));
    }

    #[Test]
    public function getTranslationLanguagesMatchesTranslations(): void
    {
        $entity = $this->makeTranslatable('en', ['en' => [], 'fr' => []]);

        self::assertSame(
            \iterator_to_array($entity->translations(), false),
            $entity->getTranslationLanguages(),
        );
    }

    // -------------------------------------------------------------------------
    // _setTranslationData()
    // -------------------------------------------------------------------------

    #[Test]
    public function setTranslationDataHydratesStateAndResetsActiveLangcode(): void
    {
        $entity = new FixtureTranslatableEntity([]);
        $entity->_setTranslationData(['en' => ['title' => 'Hello'], 'fr' => []], 'en');

        self::assertSame('en', $entity->defaultLangcode());
        self::assertSame('en', $entity->activeLangcode());
        self::assertTrue($entity->hasTranslation('fr'));
    }

    // -------------------------------------------------------------------------
    // Non-translatable entity guard
    // -------------------------------------------------------------------------

    #[Test]
    public function hasTranslationThrowsForNonTranslatableType(): void
    {
        $entity = new FixtureNonTranslatableEntity(['default_langcode' => 'en']);

        $this->expectException(EntityTranslationException::class);
        $this->expectExceptionMessage('setting');

        $entity->hasTranslation('en');
    }

    #[Test]
    public function getTranslationThrowsForNonTranslatableType(): void
    {
        $entity = new FixtureNonTranslatableEntity(['default_langcode' => 'en']);

        $this->expectException(EntityTranslationException::class);

        $entity->getTranslation('en');
    }

    #[Test]
    public function addTranslationThrowsForNonTranslatableType(): void
    {
        $entity = new FixtureNonTranslatableEntity(['default_langcode' => 'en']);

        $this->expectException(EntityTranslationException::class);

        $entity->addTranslation('fr');
    }

    #[Test]
    public function removeTranslationThrowsForNonTranslatableType(): void
    {
        $entity = new FixtureNonTranslatableEntity(['default_langcode' => 'en']);

        $this->expectException(EntityTranslationException::class);

        $entity->removeTranslation('fr');
    }

    #[Test]
    public function translationsThrowsForNonTranslatableType(): void
    {
        $entity = new FixtureNonTranslatableEntity(['default_langcode' => 'en']);

        $this->expectException(EntityTranslationException::class);

        // Must materialize the generator to trigger the guard.
        \iterator_to_array($entity->translations(), false);
    }

    #[Test]
    public function implementsTranslatableInterface(): void
    {
        $entity = $this->makeTranslatable('en', ['en' => []]);

        self::assertInstanceOf(TranslatableInterface::class, $entity);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, array<string, mixed>> $translationData
     */
    private function makeTranslatable(string $defaultLc, array $translationData = []): FixtureTranslatableEntity
    {
        $entity = new FixtureTranslatableEntity(['default_langcode' => $defaultLc]);

        if ($translationData !== []) {
            $entity->_setTranslationData($translationData, $defaultLc);
        }

        return $entity;
    }
}

// ---------------------------------------------------------------------------
// Inline fixtures
// ---------------------------------------------------------------------------

/**
 * Minimal content entity for a translatable type.
 */
final class FixtureTranslatableEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'article', ['id' => 'id', 'uuid' => 'uuid']);
    }
}

/**
 * Minimal content entity for a non-translatable type.
 */
final class FixtureNonTranslatableEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'setting', ['id' => 'id', 'uuid' => 'uuid']);
    }
}
