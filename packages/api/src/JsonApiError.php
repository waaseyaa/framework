<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

/**
 * Value object representing a JSON:API error object.
 *
 * @see https://jsonapi.org/format/#error-objects
 * @api
 */
final readonly class JsonApiError
{
    /**
     * @param string      $status HTTP status code as a string.
     * @param string      $title  Short, human-readable summary of the problem.
     * @param string      $detail Detailed explanation specific to this occurrence.
     * @param string      $code   Machine-readable error code (e.g. 'FORBIDDEN', 'NOT_FOUND').
     * @param array<string, string> $source An object containing references to the primary source of the error.
     * @param array<string, mixed> $meta Non-standard meta-information about the error
     *     (JSON:API error-object `meta` member). Emitted only when non-empty, so every
     *     pre-existing error response is byte-identical (optimistic-locking-01KTXCHY,
     *     contract conflict-surfaces.md §13).
     */
    public function __construct(
        public string $status,
        public string $title,
        public string $detail = '',
        public string $code = '',
        public array $source = [],
        public array $meta = [],
    ) {}

    /**
     * Serialize this error to a JSON:API-compliant array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $error = [
            'status' => $this->status,
            'title' => $this->title,
        ];

        if ($this->code !== '') {
            $error['code'] = $this->code;
        }

        if ($this->detail !== '') {
            $error['detail'] = $this->detail;
        }

        if ($this->source !== []) {
            $error['source'] = $this->source;
        }

        if ($this->meta !== []) {
            $error['meta'] = $this->meta;
        }

        return $error;
    }

    /**
     * Create a 404 Not Found error.
     */
    public static function notFound(string $detail = ''): self
    {
        return new self(
            status: '404',
            title: 'Not Found',
            detail: $detail,
        );
    }

    /**
     * Create a 403 Forbidden error.
     */
    public static function forbidden(string $detail = ''): self
    {
        return new self(
            status: '403',
            title: 'Forbidden',
            detail: $detail,
            code: 'FORBIDDEN',
        );
    }

    /**
     * Create a 422 Unprocessable Entity error.
     */
    public static function unprocessable(string $detail = '', array $source = []): self
    {
        return new self(
            status: '422',
            title: 'Unprocessable Entity',
            detail: $detail,
            source: $source,
        );
    }

    /**
     * Create a 400 Bad Request error.
     */
    public static function badRequest(string $detail = ''): self
    {
        return new self(
            status: '400',
            title: 'Bad Request',
            detail: $detail,
        );
    }

    /**
     * Create a 409 Conflict error.
     *
     * The defaults keep the pre-existing codeless 409 shape (the `data.id`-vs-uuid
     * mismatch) byte-identical; a revision conflict passes `code: 'REVISION_CONFLICT'`
     * plus meta as the machine-readable discriminator between the two 409s.
     *
     * @param array<string, mixed> $meta
     */
    public static function conflict(string $detail = '', string $code = '', array $meta = []): self
    {
        return new self(
            status: '409',
            title: 'Conflict',
            detail: $detail,
            code: $code,
            meta: $meta,
        );
    }

    /**
     * Create a 500 Internal Server Error.
     */
    public static function internalError(string $detail = ''): self
    {
        return new self(
            status: '500',
            title: 'Internal Server Error',
            detail: $detail,
        );
    }
}
