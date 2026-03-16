<?php

declare(strict_types=1);

namespace Waaseyaa\GitHub;

final class GitHubException extends \RuntimeException
{
    public static function apiError(int $statusCode, string $message): self
    {
        return new self(sprintf('GitHub API error (%d): %s', $statusCode, $message), $statusCode);
    }

    public static function notFound(string $resource, int|string $id): self
    {
        return new self(sprintf('GitHub %s #%s not found', $resource, $id), 404);
    }
}
