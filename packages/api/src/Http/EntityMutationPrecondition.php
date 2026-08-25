<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Api\JsonApiError;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;

/**
 * JSON:API envelope adapter for {@see EntityMutationToken::fromHttpIfMatch()}.
 *
 * EntityMutationToken remains the single policy authority. This class does not
 * parse ETags itself and never calls {@see Request::getETags()}.
 *
 * Ownership is L4 `waaseyaa/api` because the envelope types live here. L0
 * `JsonApiRouter` already imports those types under the kernel exemption.
 * Admin/page-builder body-token surfaces keep {@see EntityMutationToken::fromOpaqueString()}
 * and {@see \Waaseyaa\AdminSurface\Host\AdminSurfaceResultData}; a universal
 * helper would either pull JSON:API types into L1 entity or force Admin onto
 * If-Match.
 *
 * @api
 */
final class EntityMutationPrecondition
{
    public const string REQUIRED_CODE = 'MUTATION_PRECONDITION_REQUIRED';
    public const string INVALID_CODE = 'INVALID_MUTATION_PRECONDITION';
    public const string FAILED_CODE = 'MUTATION_PRECONDITION_FAILED';

    public const string REQUIRED_DETAIL = 'Existing-entity mutation requires exactly one strong If-Match value from the loaded resource.';
    public const string FAILED_DETAIL = 'The resource changed after the supplied mutation precondition was observed.';

    /**
     * Parse the raw If-Match header. Empty/missing is 428; anything
     * {@see EntityMutationToken::fromHttpIfMatch()} rejects is 400.
     */
    public static function fromIfMatch(?string $header): EntityMutationToken|JsonApiDocument
    {
        if ($header === null || trim($header) === '') {
            return self::requiredDocument();
        }

        try {
            return EntityMutationToken::fromHttpIfMatch(trim($header));
        } catch (\InvalidArgumentException $exception) {
            return self::invalidDocument($exception->getMessage());
        }
    }

    /**
     * Read If-Match from the request header map. Do not substitute
     * {@see Request::getETags()}, which would accept weak validators and lists.
     */
    public static function fromRequest(Request $request): EntityMutationToken|JsonApiDocument
    {
        return self::fromIfMatch($request->headers->get('If-Match'));
    }

    public static function requiredError(): JsonApiError
    {
        return new JsonApiError(
            status: '428',
            title: 'Precondition Required',
            detail: self::REQUIRED_DETAIL,
            code: self::REQUIRED_CODE,
        );
    }

    public static function invalidError(string $detail): JsonApiError
    {
        return new JsonApiError(
            status: '400',
            title: 'Bad Request',
            detail: $detail,
            code: self::INVALID_CODE,
        );
    }

    /**
     * Stale/mismatched expectation. The document must never carry the winning
     * token, ETag, or other current-authority fields.
     */
    public static function failedError(): JsonApiError
    {
        return new JsonApiError(
            status: '412',
            title: 'Precondition Failed',
            detail: self::FAILED_DETAIL,
            code: self::FAILED_CODE,
        );
    }

    public static function requiredDocument(): JsonApiDocument
    {
        return JsonApiDocument::fromErrors([self::requiredError()], statusCode: 428);
    }

    public static function invalidDocument(string $detail): JsonApiDocument
    {
        return JsonApiDocument::fromErrors([self::invalidError($detail)], statusCode: 400);
    }

    public static function failedDocument(): JsonApiDocument
    {
        return JsonApiDocument::fromErrors([self::failedError()], statusCode: 412);
    }

    public static function response(JsonApiDocument $document): JsonApiResponse
    {
        return new JsonApiResponse($document->toArray(), $document->statusCode);
    }
}
