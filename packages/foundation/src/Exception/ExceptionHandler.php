<?php

declare(strict_types=1);

namespace Aurora\Foundation\Exception;

final class ExceptionHandler
{
    /** @var list<class-string<\Throwable>> */
    private array $dontReport = [];

    public function __construct(
        private readonly RequestContext $context = new RequestContext(),
    ) {}

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
        if ($e instanceof AuroraException) {
            return $this->renderAuroraException($e);
        }

        return $this->renderGenericException($e);
    }

    public function renderForCli(\Throwable $e): string
    {
        if ($e instanceof AuroraException) {
            return sprintf(
                "[%s] %s\n  Type: %s\n  Status: %d",
                (new \ReflectionClass($e))->getShortName(),
                $e->getMessage(),
                $e->problemType,
                $e->statusCode,
            );
        }

        return sprintf(
            "[%s] %s\n  File: %s:%d",
            (new \ReflectionClass($e))->getShortName(),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        );
    }

    private function renderAuroraException(AuroraException $e): array
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
        return [
            'errors' => [
                [
                    'type' => 'aurora:internal-error',
                    'title' => 'Internal Server Error',
                    'detail' => $e->getMessage(),
                    'status' => 500,
                ],
            ],
        ];
    }
}
