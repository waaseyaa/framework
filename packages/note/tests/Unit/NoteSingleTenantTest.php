<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Note\Note;

#[CoversClass(Note::class)]
final class NoteSingleTenantTest extends TestCase
{
    #[Test]
    public function noteDoesNotHaveGetTenantIdMethod(): void
    {
        $this->assertFalse(
            method_exists(Note::class, 'getTenantId'),
            'Note must not have getTenantId() — v1.0 is single-tenant',
        );
    }

    #[Test]
    public function noteCanBeCreatedWithoutTenantId(): void
    {
        $note = new Note(['title' => 'Test Note', 'body' => 'Hello']);
        $this->assertSame('Test Note', $note->getTitle());
        $this->assertSame('Hello', $note->getBody());
    }
}
