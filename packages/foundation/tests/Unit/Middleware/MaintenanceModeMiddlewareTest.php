<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Maintenance\MaintenanceState;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\MaintenanceModeMiddleware;

#[CoversClass(MaintenanceModeMiddleware::class)]
final class MaintenanceModeMiddlewareTest extends TestCase
{
    private string $flagPath;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/waaseyaa-maint-mw-' . bin2hex(random_bytes(6));
        mkdir($dir . '/storage', 0o755, true);
        $this->flagPath = $dir . '/storage/maintenance.flag';
    }

    protected function tearDown(): void
    {
        @unlink($this->flagPath);
        @rmdir(\dirname($this->flagPath));
        @rmdir(\dirname($this->flagPath, 2));
    }

    private function middleware(bool $trustLocalhost = true, array $healthPaths = ['/health']): MaintenanceModeMiddleware
    {
        return new MaintenanceModeMiddleware(
            new MaintenanceState($this->flagPath),
            $healthPaths,
            $trustLocalhost,
        );
    }

    private function request(string $path = '/', string $remoteAddr = '203.0.113.9', string $accept = 'text/html'): Request
    {
        return Request::create(
            $path,
            'GET',
            server: ['REMOTE_ADDR' => $remoteAddr, 'HTTP_ACCEPT' => $accept],
        );
    }

    /** A spy handler that records whether the pipeline was allowed to continue. */
    private function spyNext(): HttpHandlerInterface
    {
        return new class implements HttpHandlerInterface {
            public bool $called = false;

            public function handle(Request $request): Response
            {
                $this->called = true;

                return new Response('OK', 200);
            }
        };
    }

    #[Test]
    public function serves_branded_503_with_retry_after_and_security_headers_when_active(): void
    {
        new MaintenanceState($this->flagPath)->activate(240, 'Back soon.');

        $response = $this->middleware()->maintenanceResponse($this->request());

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('240', $response->headers->get('Retry-After'));
        self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('Back soon.', (string) $response->getContent());
        // Reuses SecurityHeadersMiddleware defaults — the branded page is still hardened.
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    #[Test]
    public function serves_json_api_503_when_client_accepts_json(): void
    {
        new MaintenanceState($this->flagPath)->activate(120, null);

        $response = $this->middleware()->maintenanceResponse(
            $this->request(accept: 'application/vnd.api+json'),
        );

        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('503', $payload['errors'][0]['status']);
    }

    #[Test]
    public function passes_through_when_not_in_maintenance(): void
    {
        $next = $this->spyNext();

        $response = $this->middleware()->process($this->request(), $next);

        self::assertTrue($next->called, 'A non-maintenance request must reach the pipeline.');
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function localhost_is_exempt_when_trust_enabled(): void
    {
        new MaintenanceState($this->flagPath)->activate(120, null);
        $next = $this->spyNext();

        $response = $this->middleware(trustLocalhost: true)
            ->process($this->request(remoteAddr: '127.0.0.1'), $next);

        self::assertTrue($next->called, 'Loopback clients bypass the gate so operators can still reach the app.');
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function localhost_exemption_is_off_when_trust_disabled(): void
    {
        new MaintenanceState($this->flagPath)->activate(120, null);
        $next = $this->spyNext();

        // Same-host reverse-proxy topology: external traffic arrives as 127.0.0.1.
        // With the knob off, even loopback must get the 503.
        $response = $this->middleware(trustLocalhost: false)
            ->process($this->request(remoteAddr: '127.0.0.1'), $next);

        self::assertFalse($next->called, 'With trust-localhost disabled, loopback is NOT exempt.');
        self::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function configured_health_path_is_exempt(): void
    {
        new MaintenanceState($this->flagPath)->activate(120, null);
        $next = $this->spyNext();

        $response = $this->middleware(healthPaths: ['/health'])
            ->process($this->request(path: '/health'), $next);

        self::assertTrue($next->called, 'Health/readiness probes must bypass the gate.');
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function corrupt_flag_fails_closed_to_503(): void
    {
        file_put_contents($this->flagPath, 'not json at all');
        $next = $this->spyNext();

        $response = $this->middleware()->process($this->request(), $next);

        self::assertFalse($next->called);
        self::assertSame(503, $response->getStatusCode());
    }
}
