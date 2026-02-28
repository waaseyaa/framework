<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Result;

use Aurora\Foundation\Result\DomainError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DomainError::class)]
final class DomainErrorTest extends TestCase
{
    #[Test]
    public function entity_not_found_factory(): void
    {
        $error = DomainError::entityNotFound('node', '42');

        $this->assertSame('aurora:entity/not-found', $error->type);
        $this->assertSame('Entity Not Found', $error->title);
        $this->assertStringContainsString('Node', $error->detail);
        $this->assertStringContainsString('42', $error->detail);
        $this->assertSame(404, $error->statusCode);
    }

    #[Test]
    public function access_denied_factory(): void
    {
        $error = DomainError::accessDenied('update', 'node', '42');

        $this->assertSame('aurora:access/denied', $error->type);
        $this->assertSame(403, $error->statusCode);
    }

    #[Test]
    public function validation_failed_factory(): void
    {
        $violations = ['title' => 'Title is required', 'body' => 'Body is too short'];
        $error = DomainError::validationFailed($violations);

        $this->assertSame('aurora:validation/failed', $error->type);
        $this->assertSame(422, $error->statusCode);
        $this->assertSame($violations, $error->meta['violations']);
    }

    #[Test]
    public function translation_missing_factory(): void
    {
        $error = DomainError::translationMissing('node', '42', 'fr');

        $this->assertSame('aurora:i18n/translation-missing', $error->type);
        $this->assertSame(404, $error->statusCode);
    }

    #[Test]
    public function to_array_returns_rfc9457_structure(): void
    {
        $error = DomainError::entityNotFound('node', '42');
        $array = $error->toArray();

        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('detail', $array);
        $this->assertArrayHasKey('status', $array);
    }
}
