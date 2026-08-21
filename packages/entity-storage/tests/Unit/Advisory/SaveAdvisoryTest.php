<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Advisory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

#[CoversClass(SaveAdvisory::class)]
final class SaveAdvisoryTest extends TestCase
{
    #[Test]
    public function token_is_deterministic_for_the_same_candidate_and_ignores_message_copy_edits(): void
    {
        $entity = $this->entity('7', 'page', 'news');

        $first = SaveAdvisory::forEntityField(
            $entity,
            code: 'RESERVED_PAGE_SLUG',
            field: 'name',
            message: 'The short route is reserved; use /pages/news.',
        );
        $copyEdited = SaveAdvisory::forEntityField(
            $entity,
            code: 'RESERVED_PAGE_SLUG',
            field: 'name',
            message: 'Reserved route. This page remains available at /pages/news.',
        );

        self::assertSame($first->acknowledgement, $copyEdited->acknowledgement);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->acknowledgement);
        self::assertSame([
            'code' => 'RESERVED_PAGE_SLUG',
            'field' => 'name',
            'severity' => 'warning',
            'message' => 'The short route is reserved; use /pages/news.',
            'acknowledgement' => $first->acknowledgement,
        ], $first->payload());
    }

    #[Test]
    public function changing_candidate_entity_bundle_code_or_field_invalidates_the_token(): void
    {
        $baseline = SaveAdvisory::forEntityField(
            $this->entity('7', 'page', 'news'),
            code: 'RESERVED_PAGE_SLUG',
            field: 'name',
            message: 'Review.',
        );

        $variants = [
            SaveAdvisory::forEntityField($this->entity('7', 'page', 'events'), 'RESERVED_PAGE_SLUG', 'name', 'Review.'),
            SaveAdvisory::forEntityField($this->entity('8', 'page', 'news'), 'RESERVED_PAGE_SLUG', 'name', 'Review.'),
            SaveAdvisory::forEntityField($this->entity('7', 'article', 'news'), 'RESERVED_PAGE_SLUG', 'name', 'Review.'),
            SaveAdvisory::forEntityField($this->entity('7', 'page', 'news'), 'ANOTHER_ROUTE_WARNING', 'name', 'Review.'),
            SaveAdvisory::forEntityField($this->entity('7', 'page', 'news'), 'RESERVED_PAGE_SLUG', 'label', 'Review.'),
        ];

        foreach ($variants as $variant) {
            self::assertNotSame($baseline->acknowledgement, $variant->acknowledgement);
        }
    }

    #[Test]
    public function malformed_identifiers_messages_and_candidate_values_fail_closed(): void
    {
        $entity = $this->entity('7', 'page', 'news');

        foreach ([
            ['', 'name', 'Review.'],
            ['lowercase', 'name', 'Review.'],
            ['OK', 'name', 'Review.'],
            ['RESERVED_PAGE_SLUG', '7name', 'Review.'],
            ['RESERVED_PAGE_SLUG', 'name', ''],
            ['RESERVED_PAGE_SLUG', 'name', str_repeat('x', 1_001)],
        ] as [$code, $field, $message]) {
            try {
                SaveAdvisory::forEntityField($entity, $code, $field, $message);
                self::fail('Malformed advisory input was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }

        $entity->set('metadata', ['unsupported' => new \stdClass()]);
        $this->expectException(\InvalidArgumentException::class);
        SaveAdvisory::forEntityField($entity, 'UNSUPPORTED_VALUE', 'metadata', 'Review.');
    }

    private function entity(string $id, string $bundle, string $name): TestStorageEntity
    {
        $entity = new TestStorageEntity(
            values: [
                'id' => $id,
                'uuid' => "00000000-0000-7000-8000-00000000000{$id}",
                'bundle' => $bundle,
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
        $entity->enforceIsNew(false);

        return $entity;
    }
}
