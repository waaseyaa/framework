<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Listener;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;

/**
 * HTTP middleware that records API request audit events.
 *
 * Runs after AuthorizationMiddleware (priority 10) at priority 5 (inner
 * onion layer — later in request-handling, earlier in response post-processing).
 *
 * - 200-299 + GET on entity route → entity.read.
 * - 200-299 on export route (name contains 'export') → entity.export.
 * - 403 → access.denied.
 * - Other responses are not audited at this layer.
 *
 * Anonymous traffic → account_uid = 0 (AnonymousUser sentinel).
 * Reads `_account` attribute (NEVER `account` per CLAUDE.md §Architecture-Gotchas).
 *
 * Best-effort: exceptions are caught and logged; the primary response is always
 * returned regardless of audit write failure (NFR-001).
 *
 * @api
 */
#[AsMiddleware(pipeline: 'http', priority: 5)]
final class ApiRequestAuditListener implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly AuditWriterInterface $writer,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        try {
            $account = $request->attributes->get('_account');
            $accountUid = 0;
            if (is_object($account) && method_exists($account, 'id')) {
                $accountUid = (int) $account->id();
            }

            $routeName = (string) ($request->attributes->get('_route') ?? '');
            $statusCode = $response->getStatusCode();
            $method = $request->getMethod();
            $path = $request->getPathInfo();

            if ($statusCode === Response::HTTP_FORBIDDEN) {
                $this->writer->record(new AuditEventDescriptor(
                    kind: AuditEventKind::AccessDenied,
                    accountUid: $accountUid,
                    subjectUri: $path,
                    outcome: 'denied',
                    severity: 'warning',
                    attributes: ['path' => $path, 'method' => $method],
                ));
            } elseif ($statusCode >= 200 && $statusCode < 300 && $method === 'GET') {
                if (str_contains($routeName, 'export') || str_contains($path, '/export')) {
                    $this->writer->record(new AuditEventDescriptor(
                        kind: AuditEventKind::EntityExport,
                        accountUid: $accountUid,
                        subjectUri: $path,
                        outcome: 'allowed',
                        severity: 'info',
                        attributes: ['path' => $path, 'route' => $routeName],
                    ));
                } elseif (str_starts_with($path, '/api/') && str_contains($routeName, 'api.')) {
                    $this->writer->record(new AuditEventDescriptor(
                        kind: AuditEventKind::EntityRead,
                        accountUid: $accountUid,
                        subjectUri: $path,
                        outcome: 'allowed',
                        severity: 'info',
                        attributes: ['path' => $path, 'route' => $routeName],
                    ));
                }
            }
        } catch (\Throwable $e) {
            ($this->logger ?? new NullLogger())->warning('audit.listener_failed', [
                'listener' => self::class,
                'error'    => $e->getMessage(),
            ]);
        }

        return $response;
    }
}
