<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tenancy\CommunityScope;
use Waaseyaa\EntityStorage\Tenancy\CommunityTranslationPeerRepairer;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;
use Waaseyaa\Foundation\Community\CommunityContext;

#[CoversClass(CommunityTranslationPeerRepairer::class)]
final class CommunityTranslationPeerRepairerTest extends TestCase
{
    #[Test]
    public function repairAdoptsOnlyUnambiguousEmptyPeersAndSupportsDryRun(): void
    {
        $database = DBALDatabase::createSqlite();
        $type = new EntityType(
            id: 'repairable_translation',
            label: 'Repairable translation',
            class: TestRevisionableEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            translatable: true,
            tenancy: ['scope' => EntityType::TENANCY_SCOPE_COMMUNITY],
        );
        new SqlSchemaHandler($type, $database)->ensureTable();
        $context = new CommunityContext();
        $context->set('community-a');
        $driver = new SqlStorageDriver(
            new SingleConnectionResolver($database),
            communityScope: new CommunityScope($context),
        );
        $driver->write('repairable_translation', '1', [
            'id' => 1,
            'uuid' => 'repairable-1',
            'title' => 'English',
            'langcode' => 'en',
            'default_langcode' => 'en',
        ]);
        $this->insertPeer($database, '1', 'oj', 'en', '', 'repairable-1');
        $this->insertPeer($database, '2', 'oj', 'en', '');
        $this->insertPeer($database, '3', 'oj', 'en', 'community-b');
        $driver->write('repairable_translation', '4', [
            'id' => 4,
            'uuid' => 'repairable-4',
            'title' => 'English',
            'langcode' => 'en',
            'default_langcode' => 'en',
        ]);
        $this->insertPeer($database, '4', 'oj', 'en', '', 'mismatched-4');

        $repairer = new CommunityTranslationPeerRepairer($database);
        $dryRun = $repairer->repair($type, dryRun: true);

        self::assertSame(3, $dryRun->examined);
        self::assertSame(1, $dryRun->eligible);
        self::assertSame(0, $dryRun->repaired);
        self::assertSame(2, $dryRun->skipped);
        self::assertSame('', $this->community($database, '1', 'oj'));

        $report = $repairer->repair($type);

        self::assertSame(3, $report->examined);
        self::assertSame(1, $report->eligible);
        self::assertSame(1, $report->repaired);
        self::assertSame(2, $report->skipped);
        self::assertSame('community-a', $this->community($database, '1', 'oj'));
        self::assertSame('', $this->community($database, '2', 'oj'));
        self::assertSame('community-b', $this->community($database, '3', 'oj'));
        self::assertSame('', $this->community($database, '4', 'oj'));
        self::assertNotNull($driver->read('repairable_translation', '1', 'oj'));
    }

    private function insertPeer(
        DBALDatabase $database,
        string $id,
        string $langcode,
        string $defaultLangcode,
        string $communityId,
        ?string $uuid = null,
    ): void {
        $database->insert('repairable_translation')
            ->fields(['id', 'uuid', 'title', 'langcode', 'default_langcode', 'community_id'])
            ->values([$id, $uuid ?? 'peer-' . $id, 'Peer', $langcode, $defaultLangcode, $communityId])
            ->execute();
    }

    private function community(DBALDatabase $database, string $id, string $langcode): ?string
    {
        $query = $database->select('repairable_translation')
            ->fields('repairable_translation', ['community_id'])
            ->condition('id', $id)
            ->condition('langcode', $langcode);
        foreach ($query->execute() as $row) {
            return (string) ((array) $row)['community_id'];
        }

        return null;
    }
}
