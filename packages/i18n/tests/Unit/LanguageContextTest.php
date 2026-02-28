<?php

declare(strict_types=1);

namespace Aurora\I18n\Tests\Unit;

use Aurora\I18n\Language;
use Aurora\I18n\LanguageContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LanguageContext::class)]
final class LanguageContextTest extends TestCase
{
    private Language $english;
    private Language $french;
    private Language $arabic;

    protected function setUp(): void
    {
        $this->english = new Language(id: 'en', label: 'English', isDefault: true);
        $this->french = new Language(id: 'fr', label: 'French');
        $this->arabic = new Language(id: 'ar', label: 'Arabic', direction: 'rtl');
    }

    #[Test]
    public function it_holds_content_and_interface_languages(): void
    {
        $context = new LanguageContext(
            contentLanguage: $this->english,
            interfaceLanguage: $this->french,
        );

        $this->assertSame('en', $context->getContentLanguage()->id);
        $this->assertSame('fr', $context->getInterfaceLanguage()->id);
    }

    #[Test]
    public function it_supports_same_language_for_both_axes(): void
    {
        $context = new LanguageContext(
            contentLanguage: $this->english,
            interfaceLanguage: $this->english,
        );

        $this->assertSame('en', $context->getContentLanguage()->id);
        $this->assertSame('en', $context->getInterfaceLanguage()->id);
    }

    #[Test]
    public function with_content_language_returns_new_instance(): void
    {
        $original = new LanguageContext(
            contentLanguage: $this->english,
            interfaceLanguage: $this->french,
        );

        $modified = $original->withContentLanguage($this->arabic);

        // Original unchanged.
        $this->assertSame('en', $original->getContentLanguage()->id);
        $this->assertSame('fr', $original->getInterfaceLanguage()->id);

        // New instance has updated content language, interface language preserved.
        $this->assertSame('ar', $modified->getContentLanguage()->id);
        $this->assertSame('fr', $modified->getInterfaceLanguage()->id);

        $this->assertNotSame($original, $modified);
    }

    #[Test]
    public function with_interface_language_returns_new_instance(): void
    {
        $original = new LanguageContext(
            contentLanguage: $this->english,
            interfaceLanguage: $this->french,
        );

        $modified = $original->withInterfaceLanguage($this->arabic);

        // Original unchanged.
        $this->assertSame('en', $original->getContentLanguage()->id);
        $this->assertSame('fr', $original->getInterfaceLanguage()->id);

        // New instance has updated interface language, content language preserved.
        $this->assertSame('en', $modified->getContentLanguage()->id);
        $this->assertSame('ar', $modified->getInterfaceLanguage()->id);

        $this->assertNotSame($original, $modified);
    }

    #[Test]
    public function it_holds_optional_tenant_id(): void
    {
        $context = new LanguageContext(
            contentLanguage: $this->english,
            interfaceLanguage: $this->english,
            tenantId: 'tenant-abc',
        );

        $this->assertSame('tenant-abc', $context->getTenantId());
    }

    #[Test]
    public function tenant_id_defaults_to_null(): void
    {
        $context = new LanguageContext(
            contentLanguage: $this->english,
            interfaceLanguage: $this->english,
        );

        $this->assertNull($context->getTenantId());
    }

    #[Test]
    public function with_content_language_preserves_tenant_id(): void
    {
        $context = new LanguageContext(
            contentLanguage: $this->english,
            interfaceLanguage: $this->french,
            tenantId: 'tenant-123',
        );

        $modified = $context->withContentLanguage($this->arabic);

        $this->assertSame('tenant-123', $modified->getTenantId());
    }

    #[Test]
    public function with_interface_language_preserves_tenant_id(): void
    {
        $context = new LanguageContext(
            contentLanguage: $this->english,
            interfaceLanguage: $this->french,
            tenantId: 'tenant-123',
        );

        $modified = $context->withInterfaceLanguage($this->arabic);

        $this->assertSame('tenant-123', $modified->getTenantId());
    }

    #[Test]
    public function with_tenant_id_returns_new_instance(): void
    {
        $original = new LanguageContext(
            contentLanguage: $this->english,
            interfaceLanguage: $this->french,
        );

        $modified = $original->withTenantId('new-tenant');

        $this->assertNull($original->getTenantId());
        $this->assertSame('new-tenant', $modified->getTenantId());
        $this->assertSame('en', $modified->getContentLanguage()->id);
        $this->assertSame('fr', $modified->getInterfaceLanguage()->id);
        $this->assertNotSame($original, $modified);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $context = new LanguageContext(
            contentLanguage: $this->english,
            interfaceLanguage: $this->english,
        );

        $reflection = new \ReflectionClass($context);
        $this->assertTrue($reflection->isReadOnly());
    }
}
