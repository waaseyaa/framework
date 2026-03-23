<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\RedirectValidator;

#[CoversClass(RedirectValidator::class)]
final class RedirectValidatorTest extends TestCase
{
    private RedirectValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new RedirectValidator();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function redirectTargetProvider(): array
    {
        return [
            'relative root' => ['/', true],
            'relative path' => ['/dashboard', true],
            'relative with query' => ['/login?foo=bar', true],
            'relative nested' => ['/admin/settings/general', true],
            'empty string' => ['', false],
            'absolute URL http' => ['http://evil.com', false],
            'absolute URL https' => ['https://evil.com', false],
            'protocol-relative' => ['//evil.com', false],
            'protocol-relative with path' => ['//evil.com/path', false],
            'javascript scheme' => ['javascript:alert(1)', false],
            'backslash trick' => ['/\\evil.com', false],
            'backslash mid-path' => ['/foo\\bar', false],
            'bare domain' => ['evil.com', false],
            'data scheme' => ['data:text/html,<script>alert(1)</script>', false],
        ];
    }

    #[Test]
    #[DataProvider('redirectTargetProvider')]
    public function isSafe_validates_redirect_targets(string $target, bool $expected): void
    {
        $this->assertSame($expected, $this->validator->isSafe($target));
    }

    #[Test]
    public function sanitize_returns_target_when_safe(): void
    {
        $this->assertSame('/dashboard', $this->validator->sanitize('/dashboard'));
    }

    #[Test]
    public function sanitize_returns_fallback_when_unsafe(): void
    {
        $this->assertSame('/', $this->validator->sanitize('https://evil.com'));
    }

    #[Test]
    public function sanitize_uses_custom_fallback(): void
    {
        $this->assertSame('/home', $this->validator->sanitize('', '/home'));
    }
}
