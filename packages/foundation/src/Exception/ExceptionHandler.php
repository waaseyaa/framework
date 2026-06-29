<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Exception;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Foundation's own lightweight exception handler for HTTP API and CLI rendering.
 *
 * NOTE — Three error-rendering paths currently coexist in the framework:
 *   1. This class — standalone @api utility; callers inject it explicitly.
 *   2. packages/error-handler — the Error-Handler package renderer (full DX stack).
 *   3. HttpKernel inline boot-error path — isDebugMode() + BootFailureMessageFormatter.
 * These paths are intentionally separate for now. Reconciliation (unifying them into a
 * single pipeline) is deferred and tracked as a follow-up; do NOT merge them in this PR.
 *
 * Safe-by-default: the $debug flag defaults to false (production-safe). Pass debug: true
 * only in development/test environments. The kernel resolves APP_DEBUG env → isDebugMode()
 * and threads the result here; this class itself never reads environment variables.
 *
 * @api
 */
final class ExceptionHandler
{
    /** @var list<class-string<\Throwable>> */
    private array $dontReport = [];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly RequestContext $context = new RequestContext(),
        private readonly bool $debug = false,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param list<class-string<\Throwable>> $exceptions
     */
    public function dontReport(array $exceptions): void
    {
        $this->dontReport = $exceptions;
    }

    public function shouldReport(\Throwable $e): bool
    {
        foreach ($this->dontReport as $class) {
            if ($e instanceof $class) {
                return false;
            }
        }
        return true;
    }

    public function render(\Throwable $e): array
    {
        if ($e instanceof WaaseyaaException) {
            return $this->renderWaaseyaaException($e);
        }

        return $this->renderGenericException($e);
    }

    public function renderForCli(\Throwable $e): string
    {
        if ($e instanceof WaaseyaaException) {
            return sprintf(
                "[%s] %s\n  Type: %s\n  Status: %d",
                new \ReflectionClass($e)->getShortName(),
                $e->getMessage(),
                $e->problemType,
                $e->statusCode,
            );
        }

        $this->logger->error($e->getMessage(), [
            'exception' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        if ($this->debug) {
            return sprintf(
                "[%s] %s\n  File: %s:%d",
                new \ReflectionClass($e)->getShortName(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            );
        }

        return sprintf(
            '[%s] An unexpected error occurred.',
            new \ReflectionClass($e)->getShortName(),
        );
    }

    private function renderWaaseyaaException(WaaseyaaException $e): array
    {
        $error = $e->toApiError();

        if ($this->context->requestId !== '') {
            $error['instance'] = $this->context->requestId;
        }

        return [
            'errors' => [$error],
        ];
    }

    private function renderGenericException(\Throwable $e): array
    {
        $this->logger->error($e->getMessage(), [
            'exception' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $detail = $this->debug
            ? sprintf('[%s] %s', $e::class, $e->getMessage())
            : 'Internal Server Error';

        return [
            'errors' => [
                [
                    'type' => 'waaseyaa:internal-error',
                    'title' => 'Internal Server Error',
                    'detail' => $detail,
                    'status' => 500,
                ],
            ],
        ];
    }
}
