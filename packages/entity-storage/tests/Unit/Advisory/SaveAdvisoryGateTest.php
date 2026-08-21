<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Advisory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisoryGate;
use Waaseyaa\EntityStorage\Exception\SaveAdvisoryAcknowledgementRequiredException;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

#[CoversClass(SaveAdvisoryGate::class)]
#[CoversClass(SaveAdvisoryAcknowledgementRequiredException::class)]
final class SaveAdvisoryGateTest extends TestCase
{
    #[Test]
    public function unacknowledged_advisories_are_deduplicated_and_sorted_deterministically(): void
    {
        $entity = $this->entity();
        $zeta = SaveAdvisory::forEntityField($entity, 'ZETA_WARNING', 'name', 'Zeta.');
        $alpha = SaveAdvisory::forEntityField($entity, 'ALPHA_WARNING', 'label', 'Alpha.');

        try {
            SaveAdvisoryGate::requireAcknowledged([$zeta, $alpha, $zeta], SaveContext::default());
            self::fail('The gate accepted unacknowledged advisories.');
        } catch (SaveAdvisoryAcknowledgementRequiredException $exception) {
            self::assertSame([$alpha, $zeta], $exception->advisories());
            self::assertSame([
                $alpha->payload(),
                $zeta->payload(),
            ], $exception->advisoryPayloads());
        }
    }

    #[Test]
    public function exact_tokens_satisfy_only_their_matching_advisories(): void
    {
        $entity = $this->entity();
        $first = SaveAdvisory::forEntityField($entity, 'FIRST_WARNING', 'name', 'First.');
        $second = SaveAdvisory::forEntityField($entity, 'SECOND_WARNING', 'label', 'Second.');

        try {
            SaveAdvisoryGate::requireAcknowledged(
                [$first, $second],
                SaveContext::default()->withSaveAdvisoryAcknowledgements([$first->acknowledgement]),
            );
            self::fail('A partial acknowledgement set passed the gate.');
        } catch (SaveAdvisoryAcknowledgementRequiredException $exception) {
            self::assertSame([$second], $exception->advisories());
        }

        SaveAdvisoryGate::requireAcknowledged(
            [$first, $second],
            SaveContext::default()->withSaveAdvisoryAcknowledgements([
                $second->acknowledgement,
                $first->acknowledgement,
            ]),
        );
        $this->addToAssertionCount(1);
    }

    private function entity(): TestStorageEntity
    {
        return new TestStorageEntity(
            values: [
                'id' => '7',
                'uuid' => '00000000-0000-7000-8000-000000000007',
                'bundle' => 'page',
                'name' => 'news',
                'label' => 'News',
                'langcode' => 'en',
            ],
            entityTypeId: 'test_entity',
            entityKeys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );
    }
}
