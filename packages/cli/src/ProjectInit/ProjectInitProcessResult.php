<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\ProjectInit;

/** @internal */
final readonly class ProjectInitProcessResult
{
    public const ERROR_CHILD_START_FAILED = 'PROJECT_INIT001_CHILD_START_FAILED';
    public const ERROR_CHILD_TIMEOUT = 'PROJECT_INIT002_CHILD_TIMEOUT';
    public const ERROR_CHILD_OUTPUT_LIMIT = 'PROJECT_INIT003_CHILD_OUTPUT_LIMIT';
    public const ERROR_CHILD_CLEANUP_FAILED = 'PROJECT_INIT006_CHILD_CLEANUP_FAILED';
    public const ERROR_CHILD_CAPTURE_FAILED = 'PROJECT_INIT007_CHILD_CAPTURE_FAILED';

    public function __construct(
        public int $exitCode,
        public string $stdout = '',
        public string $stderr = '',
        public ?string $errorCode = null,
        public ?int $childPid = null,
        public ?string $diagnostic = null,
    ) {}

    public function hasRunnerError(): bool
    {
        return $this->errorCode !== null;
    }

    public static function startFailed(): self
    {
        return new self(1, errorCode: self::ERROR_CHILD_START_FAILED);
    }

    public static function timedOut(): self
    {
        return new self(1, errorCode: self::ERROR_CHILD_TIMEOUT);
    }

    public static function outputLimitExceeded(): self
    {
        return new self(1, errorCode: self::ERROR_CHILD_OUTPUT_LIMIT);
    }

    public static function captureFailed(): self
    {
        return new self(1, errorCode: self::ERROR_CHILD_CAPTURE_FAILED);
    }

    public static function cleanupFailed(int $childPid, string $diagnostic): self
    {
        return new self(
            1,
            errorCode: self::ERROR_CHILD_CLEANUP_FAILED,
            childPid: $childPid,
            diagnostic: $diagnostic,
        );
    }
}
