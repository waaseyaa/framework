<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\User\Session\SessionCookiePolicy;

#[CoversClass(AdminSurfaceServiceProvider::class)]
final class AdminSpaCsrfCookieRuntimeInjectionTest extends TestCase
{
    #[Test]
    public function packaged_html_csrf_cookie_name_is_rewritten_from_session_policy(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html><html><body><div id="__nuxt"></div>
            <script>window.__NUXT__={};window.__NUXT__.config={public:{enableRealtime:"1",csrfCookieName:"XSRF-TOKEN"},app:{baseURL:"/admin/"}}</script>
            </body></html>
            HTML;

        $rewritten = AdminSurfaceServiceProvider::applyRuntimeCsrfCookieName(
            $html,
            SessionCookiePolicy::HOST_BOUND_CSRF_COOKIE_NAME,
        );

        $this->assertStringContainsString(
            'csrfCookieName:"' . SessionCookiePolicy::HOST_BOUND_CSRF_COOKIE_NAME . '"',
            $rewritten,
        );
        $this->assertStringNotContainsString('csrfCookieName:"XSRF-TOKEN"', $rewritten);
    }
}
