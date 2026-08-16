<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\User\Session\SessionCookiePolicy;

#[CoversClass(SessionCookiePolicy::class)]
final class SessionCookiePolicyTest extends TestCase
{
    #[Test]
    public function hardened_defaults_apply_when_unconfigured(): void
    {
        $policy = new SessionCookiePolicy();

        $this->assertTrue($policy->httpOnly());
        $this->assertTrue($policy->useStrictMode());
        $this->assertSame('Lax', $policy->sameSite());
    }

    #[Test]
    public function default_secure_auto_follows_request_scheme(): void
    {
        $policy = new SessionCookiePolicy();

        $this->assertTrue($policy->resolveSecure(requestIsSecure: true));
        $this->assertFalse($policy->resolveSecure(requestIsSecure: false));
    }

    #[Test]
    public function forced_secure_true_wins_over_plaintext_request(): void
    {
        $policy = new SessionCookiePolicy(['secure' => true]);

        $this->assertTrue($policy->resolveSecure(requestIsSecure: false));
    }

    #[Test]
    public function forced_secure_false_wins_over_https_request(): void
    {
        $policy = new SessionCookiePolicy(['secure' => false]);

        $this->assertFalse($policy->resolveSecure(requestIsSecure: true));
    }

    #[Test]
    public function explicit_auto_follows_request_scheme(): void
    {
        $policy = new SessionCookiePolicy(['secure' => 'auto']);

        $this->assertTrue($policy->resolveSecure(requestIsSecure: true));
        $this->assertFalse($policy->resolveSecure(requestIsSecure: false));
    }

    #[Test]
    public function truthy_string_secure_is_coerced_like_session_ini(): void
    {
        // SessionMiddleware coerces via FILTER_VALIDATE_BOOLEAN; the policy
        // must match so both cookies read one config value identically.
        $this->assertTrue((new SessionCookiePolicy(['secure' => '1']))->resolveSecure(requestIsSecure: false));
        $this->assertTrue((new SessionCookiePolicy(['secure' => 'on']))->resolveSecure(requestIsSecure: false));
        $this->assertFalse((new SessionCookiePolicy(['secure' => '0']))->resolveSecure(requestIsSecure: true));
    }

    #[Test]
    public function samesite_override_is_returned_verbatim(): void
    {
        $policy = new SessionCookiePolicy(['samesite' => 'Strict']);

        $this->assertSame('Strict', $policy->sameSite());
    }

    #[Test]
    public function empty_samesite_opts_out(): void
    {
        $policy = new SessionCookiePolicy(['samesite' => '']);

        $this->assertNull($policy->sameSite());
    }

    #[Test]
    public function non_string_samesite_opts_out(): void
    {
        $policy = new SessionCookiePolicy(['samesite' => null]);

        $this->assertNull($policy->sameSite());
    }

    #[Test]
    public function invalid_samesite_falls_back_to_the_lax_default(): void
    {
        // A typo must not become a hard failure downstream: Symfony's
        // Cookie::withSameSite() throws on anything outside lax/strict/none,
        // so the policy normalizes unknown values to the hardened default
        // instead of letting one config typo 500 every response (#2149 review).
        $this->assertSame('Lax', (new SessionCookiePolicy(['samesite' => 'Laxx']))->sameSite());
        $this->assertSame('Lax', (new SessionCookiePolicy(['samesite' => ' Lax']))->sameSite());
        $this->assertSame('Lax', (new SessionCookiePolicy(['samesite' => 'lax;']))->sameSite());
    }

    #[Test]
    public function valid_samesite_values_pass_through_case_insensitively(): void
    {
        $this->assertSame('strict', (new SessionCookiePolicy(['samesite' => 'strict']))->sameSite());
        $this->assertSame('None', (new SessionCookiePolicy(['samesite' => 'None']))->sameSite());
        $this->assertSame('LAX', (new SessionCookiePolicy(['samesite' => 'LAX']))->sameSite());
    }

    #[Test]
    public function overriding_one_key_keeps_the_other_defaults(): void
    {
        $policy = new SessionCookiePolicy(['httponly' => false]);

        $this->assertFalse($policy->httpOnly());
        $this->assertSame('Lax', $policy->sameSite());
        $this->assertTrue($policy->useStrictMode());
        $this->assertTrue($policy->resolveSecure(requestIsSecure: true));
        $this->assertFalse($policy->resolveSecure(requestIsSecure: false));
    }
}
