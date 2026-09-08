<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\User\Session\InvalidSessionCookiePolicyException;
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

    #[Test]
    public function compatible_defaults_expose_csrf_name_path_and_absent_domain(): void
    {
        $policy = new SessionCookiePolicy();

        $this->assertNull($policy->sessionName());
        $this->assertSame(SessionCookiePolicy::DEFAULT_CSRF_COOKIE_NAME, $policy->csrfName());
        $this->assertSame('/', $policy->path());
        $this->assertNull($policy->domain());
        $this->assertFalse($policy->hostBound());
    }

    #[Test]
    public function explicit_names_path_and_domain_are_returned(): void
    {
        $policy = new SessionCookiePolicy([
            'name' => 'APPSESSID',
            'csrf_name' => 'APP-XSRF',
            'path' => '/app',
            'domain' => 'example.test',
        ]);

        $this->assertSame('APPSESSID', $policy->sessionName());
        $this->assertSame('APP-XSRF', $policy->csrfName());
        $this->assertSame('/app', $policy->path());
        $this->assertSame('example.test', $policy->domain());
    }

    #[Test]
    public function host_bound_profile_forces_secure_host_names_path_and_omits_domain(): void
    {
        $policy = new SessionCookiePolicy(['host_bound' => true]);

        $this->assertTrue($policy->hostBound());
        $this->assertSame(SessionCookiePolicy::HOST_BOUND_SESSION_COOKIE_NAME, $policy->sessionName());
        $this->assertSame(SessionCookiePolicy::HOST_BOUND_CSRF_COOKIE_NAME, $policy->csrfName());
        $this->assertSame('/', $policy->path());
        $this->assertNull($policy->domain());
        $this->assertTrue($policy->resolveSecure(requestIsSecure: false));
    }

    #[Test]
    public function host_bound_rejects_non_root_path(): void
    {
        $this->expectException(InvalidSessionCookiePolicyException::class);
        new SessionCookiePolicy(['host_bound' => true, 'path' => '/admin']);
    }

    #[Test]
    public function host_bound_rejects_configured_domain(): void
    {
        $this->expectException(InvalidSessionCookiePolicyException::class);
        new SessionCookiePolicy(['host_bound' => true, 'domain' => 'example.test']);
    }

    #[Test]
    public function host_bound_rejects_secure_false(): void
    {
        $this->expectException(InvalidSessionCookiePolicyException::class);
        new SessionCookiePolicy(['host_bound' => true, 'secure' => false]);
    }

    #[Test]
    public function host_bound_rejects_non_host_prefixed_csrf_name(): void
    {
        $this->expectException(InvalidSessionCookiePolicyException::class);
        new SessionCookiePolicy(['host_bound' => true, 'csrf_name' => 'NOT-HOST']);
    }

    #[Test]
    public function host_bound_rejects_active_session_with_wrong_name(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name('PHPSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        try {
            $policy = new SessionCookiePolicy(['host_bound' => true]);
            try {
                $policy->assertCompatibleWithActiveSession();
                $this->fail('Expected InvalidSessionCookiePolicyException for mismatched active session name.');
            } catch (InvalidSessionCookiePolicyException) {
                $this->assertTrue(true);
            }
        } finally {
            session_write_close();
        }
    }

    #[Test]
    public function default_policy_tolerates_prestarted_session_without_explicit_binding(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name('PHPSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/legacy',
            'domain' => 'parent.test',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        try {
            $policy = new SessionCookiePolicy();
            $policy->assertCompatibleWithActiveSession();
            $this->assertTrue(true);
        } finally {
            session_write_close();
        }
    }

    #[Test]
    public function explicit_path_rejects_active_session_mismatch(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name('PHPSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/other',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        try {
            $policy = new SessionCookiePolicy(['path' => '/']);
            try {
                $policy->assertCompatibleWithActiveSession();
                $this->fail('Expected InvalidSessionCookiePolicyException for mismatched active session path.');
            } catch (InvalidSessionCookiePolicyException) {
                $this->assertTrue(true);
            }
        } finally {
            session_write_close();
        }
    }
}
