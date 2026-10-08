<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Testing\Translation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Exception\EntityTranslationException;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\TranslatableInterface;

/**
 * Reusable contract suite for {@see TranslatableInterface} (FR-058..FR-061, NFR-003, NFR-004).
 *
 * Concrete subclasses bind the abstract `makeStorage()` factory to a specific
 * storage backend (sql-blob, sql-column, in-memory, ...) and the suite verifies
 * the 12 invariants T01..T12 specified in §9.1 of the entity-storage-translations
 * spec.
 *
 * The base class is shipped under `packages/entity/testing/` and registered via
 * `autoload-dev` only — production installs (`composer install --no-dev`) must
 * NOT receive `PHPUnit\Framework\TestCase`. See R7 (research.md) and the
 * `graphql alpha.106→107` outage write-up in AGENTS.md.
 *
 * @api
 */
#[CoversNothing]
abstract class TranslatableEntityContractTest extends TestCase
{
    /**
     * Return a freshly-wired storage instance for the fixture entity type.
     *
     * Subclasses choose the backend (sql-blob, sql-column, …) and ensure the
     * schema is materialised and the EntityTypeManager is wired so
     * `ContentEntityBase::getEntityType()` returns a translatable EntityType.
     */
    abstract protected function makeStorage(): EntityStorageInterface;

    /**
     * Entity type id used for the fixture (typically `'test_translatable_entity'`).
     */
    abstract protected function fixtureEntityTypeId(): string;

    /**
     * Wall-clock budget for the contract suite (NFR-004).
     *
     * The spec target is <10s on CI hardware; we set the failure threshold
     * generously to absorb slow runners and only assert as a smoke check.
     */
    protected float $nfr004WallClockBudgetSeconds = 30.0;

    /**
     * Lifetime timer for the test method — captured in setUp(), asserted in
     * assertPostConditions() so every contract test contributes to NFR-004
     * coverage without bespoke wiring per test.
     */
    private float $startedAt = 0.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = microtime(true);
    }

    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        $elapsed = microtime(true) - $this->startedAt;
        self::assertLessThan(
            $this->nfr004WallClockBudgetSeconds,
            $elapsed,
            \sprintf(
                'NFR-004: contract test exceeded wall-clock budget (%.3fs > %.3fs).',
                $elapsed,
                $this->nfr004WallClockBudgetSeconds,
            ),
        );
    }

    // ---------------------------------------------------------------------
    // T01 — defaultLangcode()
    // ---------------------------------------------------------------------

    #[Test]
    public function t01DefaultLangcodeReturnsExpectedValueAndThrowsWhenUnset(): void
    {
        $storage = $this->makeStorage();
        $entity = $this->createDefaultEntity($storage);

        self::assertInstanceOf(TranslatableInterface::class, $entity);
        self::assertSame('en', $entity->defaultLangcode());

        // No default_langcode set → throws.
        $orphan = $storage->create([
            'bundle' => 'article',
            'label' => 'Orphan',
            'langcode' => 'en',
            'title' => 'orphan',
        ]);
        self::assertInstanceOf(TranslatableInterface::class, $orphan);

        $this->expectException(EntityTranslationException::class);
        $orphan->defaultLangcode();
    }

    // ---------------------------------------------------------------------
    // T02 — activeLangcode()
    // ---------------------------------------------------------------------

    #[Test]
    public function t02ActiveLangcodeMatchesLoadedTranslation(): void
    {
        $storage = $this->makeStorage();
        $entity = $this->createDefaultEntity($storage);
        $storage->save($entity);

        $reloaded = $storage->load($entity->id());
        self::assertNotNull($reloaded);
        self::assertInstanceOf(TranslatableInterface::class, $reloaded);
        self::assertSame('en', $reloaded->activeLangcode());

        $oj = $reloaded->addTranslation('oj');
        $oj->set('title', 'Aaniin');
        $storage->save($oj);

        $fresh = $storage->load($entity->id());
        self::assertNotNull($fresh);
        self::assertInstanceOf(TranslatableInterface::class, $fresh);
        $ojHandle = $fresh->getTranslation('oj');
        self::assertSame('oj', $ojHandle->activeLangcode());
        // Default-langcode handle remains 'en'.
        self::assertSame('en', $fresh->activeLangcode());
    }

    // ---------------------------------------------------------------------
    // T03 — hasTranslation()
    // ---------------------------------------------------------------------

    #[Test]
    public function t03HasTranslationTruthyFalsy(): void
    {
        $storage = $this->makeStorage();
        $entity = $this->createDefaultEntity($storage);
        $storage->save($entity);

        $reloaded = $storage->load($entity->id());
        self::assertNotNull($reloaded);
        self::assertInstanceOf(TranslatableInterface::class, $reloaded);

        self::assertTrue($reloaded->hasTranslation('en'));
        self::assertFalse($reloaded->hasTranslation('oj'));

        $oj = $reloaded->addTranslation('oj');
        $oj->set('title', 'Aaniin');
        $storage->save($oj);

        $fresh = $storage->load($entity->id());
        self::assertNotNull($fresh);
        self::assertInstanceOf(TranslatableInterface::class, $fresh);
        self::assertTrue($fresh->hasTranslation('en'));
        self::assertTrue($fresh->hasTranslation('oj'));
        self::assertFalse($fresh->hasTranslation('fr'));
    }

    // ---------------------------------------------------------------------
    // T04 — getTranslation()
    // ---------------------------------------------------------------------

    #[Test]
    public function t04GetTranslationReturnsInstanceAndThrowsWhenMissing(): void
    {
        $storage = $this->makeStorage();
        $entity = $this->createDefaultEntity($storage);
        $storage->save($entity);

        $reloaded = $storage->load($entity->id());
        self::assertNotNull($reloaded);
        self::assertInstanceOf(TranslatableInterface::class, $reloaded);
        $oj = $reloaded->addTranslation('oj');
        $oj->set('title', 'Aaniin');
        $storage->save($oj);

        $fresh = $storage->load($entity->id());
        self::assertNotNull($fresh);
        self::assertInstanceOf(TranslatableInterface::class, $fresh);
        $ojHandle = $fresh->getTranslation('oj');
        self::assertInstanceOf(TranslatableInterface::class, $ojHandle);
        self::assertSame('oj', $ojHandle->activeLangcode());

        // Same langcode as active returns the same instance.
        self::assertSame($fresh, $fresh->getTranslation('en'));

        $this->expectException(EntityTranslationException::class);
        $fresh->getTranslation('fr');
    }

    // ---------------------------------------------------------------------
    // T05 — addTranslation()
    // ---------------------------------------------------------------------

    #[Test]
    public function t05AddTranslationAllocatesAndThrowsOnDuplicate(): void
    {
        $storage = $this->makeStorage();
        $entity = $this->createDefaultEntity($storage);
        $storage->save($entity);

        $reloaded = $storage->load($entity->id());
        self::assertNotNull($reloaded);
        self::assertInstanceOf(TranslatableInterface::class, $reloaded);

        $oj = $reloaded->addTranslation('oj');
        self::assertSame('oj', $oj->activeLangcode());
        self::assertTrue($reloaded->hasTranslation('oj'));

        $this->expectException(EntityTranslationException::class);
        $reloaded->addTranslation('oj');
    }

    // ---------------------------------------------------------------------
    // T06 — removeTranslation(default) throws
    // ---------------------------------------------------------------------

    #[Test]
    public function t06RemoveTranslationOnDefaultLangcodeThrows(): void
    {
        $storage = $this->makeStorage();
        $entity = $this->createDefaultEntity($storage);
        $storage->save($entity);

        $reloaded = $storage->load($entity->id());
        self::assertNotNull($reloaded);
        self::assertInstanceOf(TranslatableInterface::class, $reloaded);

        $this->expectException(EntityTranslationException::class);
        $reloaded->removeTranslation($reloaded->defaultLangcode());
    }

    // ---------------------------------------------------------------------
    // T07 — removeTranslation(other) deletes row on save
    // ---------------------------------------------------------------------

    #[Test]
    public function t07RemoveTranslationOnOtherLangcodeSucceedsRowGoneAfterSave(): void
    {
        $storage = $this->makeStorage();
        $entity = $this->createDefaultEntity($storage);
        $storage->save($entity);

        $reloaded = $storage->load($entity->id());
        self::assertNotNull($reloaded);
        self::assertInstanceOf(TranslatableInterface::class, $reloaded);
        $oj = $reloaded->addTranslation('oj');
        $oj->set('title', 'Aaniin');
        $storage->save($oj);

        $afterTranslate = $storage->load($entity->id());
        self::assertNotNull($afterTranslate);
        self::assertInstanceOf(TranslatableInterface::class, $afterTranslate);
        self::assertTrue($afterTranslate->hasTranslation('oj'));

        $afterTranslate->removeTranslation('oj');
        $storage->save($afterTranslate);

        $fresh = $storage->load($entity->id());
        self::assertNotNull($fresh);
        self::assertInstanceOf(TranslatableInterface::class, $fresh);
        self::assertFalse($fresh->hasTranslation('oj'));
        self::assertTrue($fresh->hasTranslation('en'));
    }

    // ---------------------------------------------------------------------
    // T08 — translations() lists with default first
    // ---------------------------------------------------------------------

    #[Test]
    public function t08TranslationsListsWithDefaultFirst(): void
    {
        $storage = $this->makeStorage();
        $entity = $this->createDefaultEntity($storage);
        $storage->save($entity);

        $reloaded = $storage->load($entity->id());
        self::assertNotNull($reloaded);
        self::assertInstanceOf(TranslatableInterface::class, $reloaded);

        // Add translations in non-sorted order: 'oj', 'fr', 'de'.
        foreach (['oj', 'fr', 'de'] as $lc) {
            $translation = $reloaded->addTranslation($lc);
            $translation->set('title', 'Title-' . $lc);
            $storage->save($translation);
            $reloaded = $storage->load($entity->id());
            self::assertNotNull($reloaded);
            self::assertInstanceOf(TranslatableInterface::class, $reloaded);
        }

        $langcodes = $reloaded->getTranslationLanguages();
        self::assertNotEmpty($langcodes);
        self::assertSame('en', $langcodes[0], 'Default langcode is yielded first.');

        // Remaining langcodes must follow ascending lexicographic order.
        $rest = \array_slice($langcodes, 1);
        $sortedRest = $rest;
        \sort($sortedRest);
        self::assertSame($sortedRest, $rest, 'Non-default langcodes ordered ascending.');
    }

    // ---------------------------------------------------------------------
    // T09 — fieldLangcode() reports correct resolved langcode
    // ---------------------------------------------------------------------

    #[Test]
    public function t09FieldLangcodeReportsCorrectResolvedLangcode(): void
    {
        $storage = $this->makeStorage();
        $entity = $this->createDefaultEntity($storage);
        $storage->save($entity);

        $reloaded = $storage->load($entity->id());
        self::assertNotNull($reloaded);
        self::assertInstanceOf(TranslatableInterface::class, $reloaded);
        $oj = $reloaded->addTranslation('oj');
        $oj->set('title', 'Aaniin');
        $oj->set('body', 'Boozhoo.');
        $storage->save($oj);

        $fresh = $storage->load($entity->id());
        self::assertNotNull($fresh);
        self::assertInstanceOf(TranslatableInterface::class, $fresh);
        $ojHandle = $fresh->getTranslation('oj');

        // Read translatable field on active langcode: fieldLangcode is 'oj'.
        $ojHandle->get('title');
        self::assertSame('oj', $ojHandle->fieldLangcode('title'));
    }

    // ---------------------------------------------------------------------
    // T10 — non-translatable fields shared by reference (NFR-003)
    // ---------------------------------------------------------------------

    #[Test]
    public function t10NonTranslatableFieldReadsAreReferenceIdenticalAcrossTranslations(): void
    {
        $storage = $this->makeStorage();
        $entity = $this->createDefaultEntity($storage);
        $storage->save($entity);

        $reloaded = $storage->load($entity->id());
        self::assertNotNull($reloaded);
        self::assertInstanceOf(TranslatableInterface::class, $reloaded);
        $oj = $reloaded->addTranslation('oj');
        $oj->set('title', 'Aaniin');
        $storage->save($oj);

        $fresh = $storage->load($entity->id());
        self::assertNotNull($fresh);
        self::assertInstanceOf(TranslatableInterface::class, $fresh);

        $enHandle = $fresh;
        $ojHandle = $fresh->getTranslation('oj');

        $enAuthor = $enHandle->get('author_id');
        $ojAuthor = $ojHandle->get('author_id');

        // NFR-003: non-translatable field values are shared by reference across
        // translation handles — the trait does not deep-copy them per langcode.
        self::assertSame(
            $enAuthor,
            $ojAuthor,
            'NFR-003: non-translatable field read must be reference-identical across translations.',
        );

        $enCreated = $enHandle->get('created_at');
        $ojCreated = $ojHandle->get('created_at');
        self::assertSame(
            $enCreated,
            $ojCreated,
            'NFR-003: non-translatable timestamp must be reference-identical across translations.',
        );
    }

    // ---------------------------------------------------------------------
    // T11 — translatable field reads fall through configured chain
    // ---------------------------------------------------------------------

    #[Test]
    public function t11TranslatableFieldReadsFallThroughConfiguredChain(): void
    {
        $storage = $this->makeStorage();
        $entity = $this->createDefaultEntity($storage);
        $storage->save($entity);

        // Add the 'oj' translation. `addTranslation()` clones the default-row
        // values, so simply leaving fields "unset" still persists them on
        // the new row. The proper fallback test populates a translatable
        // field that exists ONLY on the default-langcode row: we write
        // `description` onto the default handle AFTER the oj translation has
        // been allocated. The oj row never sees `description`, exercising the
        // fallback chain (oj → en).
        $reloaded = $storage->load($entity->id());
        self::assertNotNull($reloaded);
        self::assertInstanceOf(TranslatableInterface::class, $reloaded);
        $oj = $reloaded->addTranslation('oj');
        $oj->set('title', 'Aaniin');
        $storage->save($oj);

        // Reload, write 'description' on the default-langcode handle only.
        $defaultHandle = $storage->load($entity->id());
        self::assertNotNull($defaultHandle);
        self::assertInstanceOf(TranslatableInterface::class, $defaultHandle);
        self::assertSame('en', $defaultHandle->activeLangcode());
        $defaultHandle->set('description', 'A description on the default row only.');
        $storage->save($defaultHandle);

        $fresh = $storage->load($entity->id());
        self::assertNotNull($fresh);
        self::assertInstanceOf(TranslatableInterface::class, $fresh);
        $ojHandle = $fresh->getTranslation('oj');

        // Wire the canonical default chain (oj → en) onto the oj handle so
        // missing 'description' falls back to the default langcode.
        $resolver = \Waaseyaa\Entity\Hydration\FallbackChainResolver::withDefaultChain('en');
        $this->wireFallbackResolver($ojHandle, $resolver);

        // 'title' resolves at the active langcode.
        self::assertSame('Aaniin', $ojHandle->get('title'));
        self::assertSame('oj', $ojHandle->fieldLangcode('title'));

        // 'description' is absent on the oj row → falls through to the
        // default langcode where it was explicitly written.
        self::assertSame('A description on the default row only.', $ojHandle->get('description'));
        self::assertSame('en', $ojHandle->fieldLangcode('description'));
    }

    // ---------------------------------------------------------------------
    // T12 — fallback exhaustion returns null, fieldLangcode returns null
    // ---------------------------------------------------------------------

    #[Test]
    public function t12FallbackExhaustionReturnsNullAndFieldLangcodeReturnsNull(): void
    {
        $storage = $this->makeStorage();
        $entity = $this->createDefaultEntity($storage);
        $storage->save($entity);

        // Add 'oj' translation with only `title`; 'body' unset on oj.
        $reloaded = $storage->load($entity->id());
        self::assertNotNull($reloaded);
        self::assertInstanceOf(TranslatableInterface::class, $reloaded);
        $oj = $reloaded->addTranslation('oj');
        $oj->set('title', 'Aaniin');
        $storage->save($oj);

        $fresh = $storage->load($entity->id());
        self::assertNotNull($fresh);
        self::assertInstanceOf(TranslatableInterface::class, $fresh);
        $ojHandle = $fresh->getTranslation('oj');

        // Wire a deliberately exhaustible chain that includes only langcodes
        // for which no value exists for 'description' (a translatable field
        // not set on either row).
        $resolver = new \Waaseyaa\Entity\Hydration\FallbackChainResolver(
            static fn (): array => ['oj', 'en'],
        );
        $this->wireFallbackResolver($ojHandle, $resolver);

        // 'description' is unset on both rows → fallback exhausts → null.
        self::assertNull($ojHandle->get('description'));
        self::assertNull($ojHandle->fieldLangcode('description'));
    }

    // ---------------------------------------------------------------------
    // Subclass helpers
    // ---------------------------------------------------------------------

    /**
     * Build, persist, and return the canonical fixture entity used by the
     * suite.
     *
     * Default fields:
     *   - title (translatable, string)    = 'Hello world'
     *   - body  (translatable, text)      = 'Greetings.'
     *   - description (translatable, text)= null (unset, used by T12)
     *   - author_id (non-translatable)    = '42'
     *   - created_at (non-translatable)   = 1747000000
     */
    protected function createDefaultEntity(EntityStorageInterface $storage): ContentEntityInterface
    {
        $entity = $storage->create([
            'bundle' => 'article',
            'label' => 'Hello',
            'langcode' => 'en',
            'default_langcode' => 'en',
            'title' => 'Hello world',
            'body' => 'Greetings.',
            'author_id' => '42',
            'created_at' => 1747000000,
        ]);
        if (!$entity instanceof ContentEntityInterface) {
            self::fail(\sprintf(
                'Fixture for %s must be a ContentEntityInterface; got %s.',
                $this->fixtureEntityTypeId(),
                $entity::class,
            ));
        }
        if (!$entity instanceof TranslatableInterface) {
            self::fail(\sprintf(
                'Fixture for %s must implement TranslatableInterface; got %s.',
                $this->fixtureEntityTypeId(),
                $entity::class,
            ));
        }

        return $entity;
    }

    /**
     * Inject the fallback resolver into the entity instance.
     *
     * The trait exposes `_setFallbackResolver()` as `@internal`. Tests reach
     * for it via the documented entry point.
     */
    protected function wireFallbackResolver(
        object $entity,
        \Waaseyaa\Entity\Hydration\FallbackChainResolver $resolver,
    ): void {
        if (!\method_exists($entity, '_setFallbackResolver')) {
            self::fail(\sprintf(
                'Entity %s is missing _setFallbackResolver(); did you wire TranslatableEntityTrait?',
                $entity::class,
            ));
        }

        $entity->_setFallbackResolver($resolver);
    }
}
