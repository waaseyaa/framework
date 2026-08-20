<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\Advisory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisoryGate;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\Exception\SaveAdvisoryAcknowledgementRequiredException;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

#[CoversClass(EntityRepository::class)]
#[CoversClass(BeforeSaveEvent::class)]
final class SaveAdvisoryRepositoryTest extends TestCase
{
    #[Test]
    public function create_aborts_before_write_then_exact_acknowledgement_saves(): void
    {
        [$repository, $dispatcher] = $this->repository();
        $afterSaveCount = 0;
        $dispatcher->addListener(BeforeSaveEvent::class, static function (BeforeSaveEvent $event): void {
            SaveAdvisoryGate::requireAcknowledged([
                SaveAdvisory::forEntityField(
                    $event->entity(),
                    'RESERVED_PAGE_SLUG',
                    'name',
                    'The short route is reserved; use /pages/news.',
                ),
            ], $event->saveContext());
        });
        $dispatcher->addListener(AfterSaveEvent::class, static function () use (&$afterSaveCount): void {
            ++$afterSaveCount;
        });

        $candidate = $this->entity('7', 'news');

        try {
            $repository->save($candidate);
            self::fail('Unacknowledged create succeeded.');
        } catch (SaveAdvisoryAcknowledgementRequiredException $exception) {
            self::assertNull($repository->find('7'));
            self::assertSame(0, $afterSaveCount);
            $token = $exception->advisories()[0]->acknowledgement;
        }

        $repository->save(
            $candidate,
            context: SaveContext::default()->withSaveAdvisoryAcknowledgements([$token]),
        );

        self::assertSame('news', $repository->find('7')?->get('name'));
        self::assertSame(1, $afterSaveCount);
    }

    #[Test]
    public function update_exposes_stored_original_and_changed_candidate_invalidates_prior_token(): void
    {
        [$repository, $dispatcher] = $this->repository();
        $repository->save($this->entity('7', 'legacy'));

        $observedOriginals = [];
        $dispatcher->addListener(BeforeSaveEvent::class, static function (BeforeSaveEvent $event) use (&$observedOriginals): void {
            $observedOriginals[] = $event->originalEntity()?->get('name');
            SaveAdvisoryGate::requireAcknowledged([
                SaveAdvisory::forEntityField($event->entity(), 'RESERVED_PAGE_SLUG', 'name', 'Review.'),
            ], $event->saveContext());
        });

        $candidate = $repository->find('7');
        self::assertNotNull($candidate);
        $candidate->set('name', 'news');

        try {
            $repository->save($candidate);
            self::fail('Unacknowledged update succeeded.');
        } catch (SaveAdvisoryAcknowledgementRequiredException $exception) {
            $newsToken = $exception->advisories()[0]->acknowledgement;
        }

        self::assertSame('legacy', $repository->find('7')?->get('name'));
        self::assertSame(['legacy'], $observedOriginals);

        $candidate->set('name', 'events');
        try {
            $repository->save(
                $candidate,
                context: SaveContext::default()->withSaveAdvisoryAcknowledgements([$newsToken]),
            );
            self::fail('A token for another candidate value succeeded.');
        } catch (SaveAdvisoryAcknowledgementRequiredException $exception) {
            $eventsToken = $exception->advisories()[0]->acknowledgement;
            self::assertNotSame($newsToken, $eventsToken);
        }

        $repository->save(
            $candidate,
            context: SaveContext::default()->withSaveAdvisoryAcknowledgements([$eventsToken]),
        );

        self::assertSame('events', $repository->find('7')?->get('name'));
        self::assertSame(['legacy', 'legacy', 'legacy'], $observedOriginals);
    }

    /** @return array{EntityRepository, EventDispatcher} */
    private function repository(): array
    {
        $entityType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );
        $dispatcher = new EventDispatcher();
        $boundary = new StorageBoundary();
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $entityType,
            new InMemoryStorageDriverV2(
                new InMemoryStorageDriver(),
                $boundary->driverRowFactory(),
                $boundary->driverSnapshotReader(),
            ),
            $dispatcher,
            storageBoundary: $boundary,
        );

        return [$repository, $dispatcher];
    }

    private function entity(string $id, string $name): TestStorageEntity
    {
        return new TestStorageEntity(
            values: [
                'id' => $id,
                'uuid' => "00000000-0000-7000-8000-00000000000{$id}",
                'bundle' => 'page',
                'name' => $name,
                'label' => 'Page',
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

