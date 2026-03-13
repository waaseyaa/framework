<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Note\Note;

#[CoversClass(Note::class)]
final class NoteTest extends TestCase
{
    #[Test]
    public function newNoteIsNew(): void
    {
        $note = new Note(['title' => 'Hello']);

        $this->assertTrue($note->isNew());
    }

    #[Test]
    public function entityTypeIdIsNote(): void
    {
        $note = new Note([]);

        $this->assertSame('note', $note->getEntityTypeId());
    }

    #[Test]
    public function getTitleReturnsTitle(): void
    {
        $note = new Note(['title' => 'My Note']);

        $this->assertSame('My Note', $note->getTitle());
    }

    #[Test]
    public function setTitleUpdatesTitle(): void
    {
        $note = new Note(['title' => 'Old']);
        $note->setTitle('New');

        $this->assertSame('New', $note->getTitle());
    }

    #[Test]
    public function getBodyReturnsBodyOrEmptyString(): void
    {
        $withBody = new Note(['title' => 'Test', 'body' => 'Content here.']);
        $withoutBody = new Note(['title' => 'Test']);

        $this->assertSame('Content here.', $withBody->getBody());
        $this->assertSame('', $withoutBody->getBody());
    }
}
