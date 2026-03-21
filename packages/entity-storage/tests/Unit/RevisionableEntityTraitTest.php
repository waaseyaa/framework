<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\RevisionableEntityTrait;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

#[CoversClass(RevisionableEntityTrait::class)]
final class RevisionableEntityTraitTest extends TestCase
{
    #[Test]
    public function revision_id_defaults_to_null(): void
    {
        $entity = new TestRevisionableEntity();
        $this->assertNull($entity->getRevisionId());
    }

    #[Test]
    public function revision_id_from_values(): void
    {
        $entity = new TestRevisionableEntity(values: ['revision_id' => 3]);
        $this->assertSame(3, $entity->getRevisionId());
    }

    #[Test]
    public function revision_id_casts_numeric_string_to_int(): void
    {
        $entity = new TestRevisionableEntity(values: ['revision_id' => '5']);
        $this->assertSame(5, $entity->getRevisionId());
    }

    #[Test]
    public function is_default_revision_defaults_to_true(): void
    {
        $entity = new TestRevisionableEntity();
        $this->assertTrue($entity->isDefaultRevision());
    }

    #[Test]
    public function is_default_revision_reads_from_values(): void
    {
        $entity = new TestRevisionableEntity(values: ['is_default_revision' => false]);
        $this->assertFalse($entity->isDefaultRevision());
    }

    #[Test]
    public function is_latest_revision_defaults_to_true(): void
    {
        $entity = new TestRevisionableEntity();
        $this->assertTrue($entity->isLatestRevision());
    }

    #[Test]
    public function is_latest_revision_reads_from_values(): void
    {
        $entity = new TestRevisionableEntity(values: ['is_latest_revision' => false]);
        $this->assertFalse($entity->isLatestRevision());
    }

    #[Test]
    public function new_revision_flag_defaults_to_null(): void
    {
        $entity = new TestRevisionableEntity();
        $this->assertNull($entity->isNewRevision());
    }

    #[Test]
    public function set_and_get_new_revision(): void
    {
        $entity = new TestRevisionableEntity();
        $entity->setNewRevision(true);
        $this->assertTrue($entity->isNewRevision());

        $entity->setNewRevision(false);
        $this->assertFalse($entity->isNewRevision());
    }

    #[Test]
    public function set_and_get_revision_log(): void
    {
        $entity = new TestRevisionableEntity();
        $this->assertNull($entity->getRevisionLog());

        $entity->setRevisionLog('Updated content');
        $this->assertSame('Updated content', $entity->getRevisionLog());
    }

    #[Test]
    public function revision_log_from_values(): void
    {
        $entity = new TestRevisionableEntity(values: ['revision_log' => 'Initial']);
        $this->assertSame('Initial', $entity->getRevisionLog());
    }
}
