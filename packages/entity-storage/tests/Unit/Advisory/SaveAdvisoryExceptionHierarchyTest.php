<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Advisory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisoryGate;
use Waaseyaa\EntityStorage\Event\AbortOperationException;
use Waaseyaa\EntityStorage\Exception\SaveAdvisoryAcknowledgementRequiredException;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

#[CoversClass(AbortOperationException::class)]
#[CoversClass(SaveAdvisoryAcknowledgementRequiredException::class)]
#[CoversClass(SaveAdvisoryGate::class)]
final class SaveAdvisoryExceptionHierarchyTest extends TestCase
{
    #[Test]
    public function abort_operation_exception_remains_final_with_unchanged_public_shape(): void
    {
        $reflection = new \ReflectionClass(AbortOperationException::class);

        self::assertTrue($reflection->isFinal());
        self::assertSame(\RuntimeException::class, $reflection->getParentClass()?->getName());

        $abort = new AbortOperationException('alias already exists', self::class);
        self::assertSame('alias already exists', $abort->reason);
        self::assertSame(self::class, $abort->subscriberFqcn);
        self::assertSame('alias already exists', $abort->getMessage());
        self::assertNotInstanceOf(SaveAdvisoryAcknowledgementRequiredException::class, $abort);
    }

    #[Test]
    public function unacknowledged_advisory_is_distinguishable_and_is_not_an_abort_operation(): void
    {
        $advisory = SaveAdvisory::forEntityField($this->entity(), 'RESERVED_PAGE_SLUG', 'name', 'Review.');

        try {
            SaveAdvisoryGate::requireAcknowledged([$advisory], SaveContext::default());
            self::fail('Unacknowledged advisories must throw.');
        } catch (SaveAdvisoryAcknowledgementRequiredException $exception) {
            self::assertNotInstanceOf(AbortOperationException::class, $exception);
            self::assertSame([$advisory], $exception->advisories());
            self::assertSame([$advisory->payload()], $exception->advisoryPayloads());
        }
    }

    #[Test]
    public function existing_abort_catches_do_not_consume_an_unacknowledged_advisory(): void
    {
        $advisory = SaveAdvisory::forEntityField($this->entity(), 'RESERVED_PAGE_SLUG', 'name', 'Review.');

        try {
            SaveAdvisoryGate::requireAcknowledged([$advisory], SaveContext::default());
            self::fail('Unacknowledged advisories must throw.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf(SaveAdvisoryAcknowledgementRequiredException::class, $thrown);
            self::assertFalse($thrown instanceof AbortOperationException);
            if (!$thrown instanceof SaveAdvisoryAcknowledgementRequiredException) {
                return;
            }
            self::assertSame('RESERVED_PAGE_SLUG', $thrown->advisories()[0]->code);
        }
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
