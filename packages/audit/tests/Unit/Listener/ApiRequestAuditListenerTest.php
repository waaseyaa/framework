<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Listener\ApiRequestAuditListener;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;

#[CoversClass(ApiRequestAuditListener::class)]
final class ApiRequestAuditListenerTest extends TestCase
{
    #[Test]
    public function it_records_entity_read_on_successful_get(): void
    {
        $recorded = [];
        $writer = new class ($recorded) implements AuditWriterInterface {
            public function __construct(private array &$recorded) {}
            public function record(AuditEventDescriptor $d): void
            {
                $this->recorded[] = $d;
            }
        };

        $request = Request::create('/api/note/1', 'GET');
        $request->attributes->set('_route', 'api.note.show');

        $handler = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('', 200);
            }
        };

        $listener = new ApiRequestAuditListener($writer);
        $response = $listener->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEventKind::EntityRead, $recorded[0]->kind);
        $this->assertSame('allowed', $recorded[0]->outcome);
    }

    #[Test]
    public function it_records_access_denied_on_403(): void
    {
        $recorded = [];
        $writer = new class ($recorded) implements AuditWriterInterface {
            public function __construct(private array &$recorded) {}
            public function record(AuditEventDescriptor $d): void
            {
                $this->recorded[] = $d;
            }
        };

        $request = Request::create('/api/node/5', 'GET');
        $request->attributes->set('_route', 'api.node.show');

        $handler = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('', 403);
            }
        };

        $listener = new ApiRequestAuditListener($writer);
        $listener->process($request, $handler);

        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEventKind::AccessDenied, $recorded[0]->kind);
        $this->assertSame('denied', $recorded[0]->outcome);
    }

    #[Test]
    public function it_always_returns_the_original_response(): void
    {
        $writer = new class implements AuditWriterInterface {
            public function record(AuditEventDescriptor $d): void {}
        };

        $request = Request::create('/api/note/1', 'GET');
        $expected = new Response('body', 200);
        $handler = new class ($expected) implements HttpHandlerInterface {
            public function __construct(private Response $resp) {}
            public function handle(Request $request): Response
            {
                return $this->resp;
            }
        };

        $listener = new ApiRequestAuditListener($writer);
        $response = $listener->process($request, $handler);

        $this->assertSame($expected, $response);
    }
}
