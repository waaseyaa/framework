<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

/**
 * Value object representing a JSON:API document.
 *
 * A JSON:API document MUST contain at least one of: data, errors, or meta.
 * The data and errors members MUST NOT coexist in the same document.
 *
 * @see https://jsonapi.org/format/#document-structure
 */
final readonly class JsonApiDocument
{
    /**
     * @param JsonApiResource|array<JsonApiResource>|null $data     Primary data (single resource, collection, or null).
     * @param array<JsonApiError>                         $errors   Error objects.
     * @param array<string, mixed>                        $meta     Top-level meta information.
     * @param array<string, string>                       $links    Top-level links (self, next, prev, etc.).
     * @param array<JsonApiResource>                      $included Sideloaded (included) resource objects.
     * @param array<string, string>                       $headers  HTTP representation metadata for the transport adapter.
     */
    /**
     * @param int $statusCode Suggested HTTP status code for the response.
     */
    public function __construct(
        public JsonApiResource|array|null $data = null,
        public array $errors = [],
        public array $meta = [],
        public array $links = [],
        public array $included = [],
        public int $statusCode = 200,
        public array $headers = [],
    ) {}

    /**
     * Serialize this document to a JSON:API-compliant array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $document = [
            'jsonapi' => [
                'version' => '1.1',
            ],
        ];

        if ($this->errors !== []) {
            $document['errors'] = array_map(
                static fn(JsonApiError $error): array => $error->toArray(),
                $this->errors,
            );
        } else {
            // data and errors MUST NOT coexist.
            if ($this->data instanceof JsonApiResource) {
                $document['data'] = $this->data->toArray();
            } elseif (\is_array($this->data)) {
                $document['data'] = array_map(
                    static fn(JsonApiResource $resource): array => $resource->toArray(),
                    $this->data,
                );
            } else {
                // null data — e.g. after a DELETE or empty result
                $document['data'] = null;
            }
        }

        if ($this->meta !== []) {
            $document['meta'] = $this->meta;
        }

        if ($this->links !== []) {
            $document['links'] = $this->links;
        }

        if ($this->included !== []) {
            $document['included'] = array_map(
                static fn(JsonApiResource $resource): array => $resource->toArray(),
                $this->included,
            );
        }

        return $document;
    }

    /**
     * Create a document containing a single resource.
     */
    public static function fromResource(JsonApiResource $resource, array $links = [], array $meta = [], int $statusCode = 200, array $headers = []): self
    {
        return new self(data: $resource, links: $links, meta: $meta, statusCode: $statusCode, headers: $headers);
    }

    /**
     * Create a document containing a collection of resources.
     *
     * @param array<JsonApiResource> $resources
     */
    public static function fromCollection(array $resources, array $links = [], array $meta = [], array $headers = []): self
    {
        return new self(data: $resources, links: $links, meta: $meta, headers: $headers);
    }

    /**
     * Create an error document.
     *
     * @param array<JsonApiError> $errors
     */
    public static function fromErrors(array $errors, array $meta = [], int $statusCode = 400): self
    {
        return new self(errors: $errors, meta: $meta, statusCode: $statusCode);
    }

    /**
     * Create a document with null data (e.g. after a DELETE).
     */
    public static function empty(array $meta = [], int $statusCode = 200, array $headers = []): self
    {
        return new self(data: null, meta: $meta, statusCode: $statusCode, headers: $headers);
    }
}
