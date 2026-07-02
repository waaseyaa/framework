<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\AttachmentActiveInvariant;
use Waaseyaa\Attachment\Schema\AttachmentSchema;
use Waaseyaa\Database\DBALDatabase;

#[CoversClass(AttachmentActiveInvariant::class)]
#[CoversClass(Attachment::class)]
final class AttachmentActiveInvariantTest extends TestCase
{
    /**
     * The strict allow-list: exactly the representations SQLite/MySQL
     * hydration and in-memory boolean assignment actually produce for a
     * truthy `is_active`. Everything else — including PHP-truthy garbage
     * like the string 'false' — is NOT active, so a malformed value can
     * never trigger a sibling demotion.
     *
     * @return array<string, array{mixed, bool}>
     */
    public static function isActiveCases(): array
    {
        return [
            'bool true' => [true, true],
            'int 1' => [1, true],
            'string 1' => ['1', true],
            'bool false' => [false, false],
            'int 0' => [0, false],
            'string 0' => ['0', false],
            'null' => [null, false],
            'empty string' => ['', false],
            // (bool) 'false' === true — the exact footgun the allow-list
            // exists to close. A garbage string must never read as active.
            'garbage string false' => ['false', false],
            'garbage string yes' => ['yes', false],
            'int 2' => [2, false],
        ];
    }

    #[Test]
    #[DataProvider('isActiveCases')]
    public function isActiveUsesAStrictAllowList(mixed $value, bool $expected): void
    {
        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '1',
            'filename' => 'x.pdf',
        ]);
        // Bypass any constructor-time typed-property coercion: write the raw
        // value directly into the value bag the way hydration does.
        $attachment->set('is_active', $value);

        self::assertSame($expected, AttachmentActiveInvariant::isActive($attachment));
    }

    /**
     * demoteSiblings() issues a raw `UPDATE ... SET is_active = 0` — it must
     * also stamp `updated_at` so a demoted row's audit trail reflects the
     * demotion instead of freezing at whatever value was last written.
     */
    #[Test]
    public function demoteSiblingsStampsUpdatedAtOnEveryDemotedRow(): void
    {
        $database = DBALDatabase::createSqlite();
        new AttachmentSchema($database)->ensureTable();

        $staleTimestamp = 1_000;
        // One active, one inactive — demoteSiblings() has no is_active
        // condition in its WHERE clause, so both rows are touched (and must
        // both get updated_at bumped) regardless of their starting state.
        // Seeding both as active would trip the WP2 partial unique index.
        foreach (['a.pdf' => 1, 'b.pdf' => 0] as $filename => $isActive) {
            $row = [
                'uuid' => bin2hex(random_bytes(8)),
                'bundle' => 'attachment',
                'filename' => $filename,
                'langcode' => 'en',
                'parent_entity_type' => 'node',
                'parent_entity_id' => '1',
                'is_active' => $isActive,
                'created_at' => $staleTimestamp,
                'updated_at' => $staleTimestamp,
                '_data' => '{}',
            ];
            $database->insert('attachment')->fields(array_keys($row))->values($row)->execute();
        }

        $before = time();
        AttachmentActiveInvariant::demoteSiblings($database, 'node', '1', null);

        $rows = iterator_to_array($database->select('attachment')
            ->fields('attachment', ['is_active', 'updated_at'])
            ->condition('parent_entity_type', 'node')
            ->condition('parent_entity_id', '1')
            ->execute());

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame(0, (int) $row['is_active'], 'Every sibling must be demoted.');
            self::assertGreaterThanOrEqual(
                $before,
                (int) $row['updated_at'],
                'updated_at must be bumped from the stale seed value on demotion.',
            );
        }
    }
}
