<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\Exception\EntityTranslationException;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableEntityTrait;

/**
 * WP04 / T026 — historical-revision-write trigger semantics (FR-017).
 *
 * The full storage coordinator (which evaluates `isCurrentRevision()` on save
 * and dispatches the exception) is composed in a later WP. This test pins the
 * trigger policy: a save path that inspects `RevisionableEntityInterface::isCurrentRevision()`
 * and raises `EntityTranslationException::historicalRevisionWrite($vid, $langcode)`
 * when the flag is `false` is the only acceptable shape for FR-017.
 *
 * The minimal save policy is encoded in {@see self::guardHistoricalWrite()} below.
 * Once the coordinator wiring lands, that policy moves into the coordinator and
 * this test continues to pass by exercising the contract from the trait side.
 */
#[CoversNothing]
final class HistoricalRevisionWriteTest extends TestCase
{
    #[Test]
    public function savingHistoricalRevisionRaisesHistoricalRevisionWrite(): void
    {
        $entity = $this->makeRevisionableEntity(vid: 7, langcode: 'oj', current: false);

        $this->expectException(EntityTranslationException::class);
        $this->expectExceptionMessage('historical revision');

        $this->guardHistoricalWrite($entity, langcode: 'oj');
    }

    #[Test]
    public function savingCurrentRevisionDoesNotRaise(): void
    {
        $entity = $this->makeRevisionableEntity(vid: 9, langcode: 'oj', current: true);

        // No exception expected; assertion documents the happy path.
        $this->guardHistoricalWrite($entity, langcode: 'oj');
        self::assertTrue($entity->isCurrentRevision());
    }

    #[Test]
    public function historicalRevisionWriteCarriesStableCode(): void
    {
        $entity = $this->makeRevisionableEntity(vid: 7, langcode: 'oj', current: false);

        try {
            $this->guardHistoricalWrite($entity, langcode: 'oj');
            self::fail('Expected EntityTranslationException for historical-revision save.');
        } catch (EntityTranslationException $ex) {
            self::assertSame('historical_revision_write', $ex->getCode());
            self::assertStringContainsString('7', $ex->getMessage());
            self::assertStringContainsString('oj', $ex->getMessage());
        }
    }

    /**
     * Save-time policy: if the entity is not the current revision, raise.
     *
     * Mirrors the contract in exception-surface.md §3.2: the coordinator
     * inspects `isCurrentRevision()` and dispatches the typed factory.
     */
    private function guardHistoricalWrite(RevisionableEntityInterface $entity, string $langcode): void
    {
        if (!$entity->isCurrentRevision()) {
            $vid = $entity->revisionId();
            // Coordinator coerces to int for the typed factory; null/string vids
            // are not expected on historical-load instances.
            throw EntityTranslationException::historicalRevisionWrite((int) $vid, $langcode);
        }
    }

    private function makeRevisionableEntity(int $vid, string $langcode, bool $current): RevisionableEntityInterface
    {
        return new class ($vid, $langcode, $current) extends ContentEntityBase implements RevisionableEntityInterface {
            use RevisionableEntityTrait;

            public function __construct(int $vid, string $langcode, bool $current)
            {
                parent::__construct(
                    values: [
                        'id' => 1,
                        'langcode' => $langcode,
                        'revision_id' => $vid,
                        'is_latest_revision' => $current,
                    ],
                    entityTypeId: 'teaching',
                    entityKeys: ['id' => 'id', 'langcode' => 'langcode', 'revision' => 'revision_id'],
                );
            }
        };
    }
}
