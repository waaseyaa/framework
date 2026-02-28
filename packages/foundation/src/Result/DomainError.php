<?php

declare(strict_types=1);

namespace Aurora\Foundation\Result;

final readonly class DomainError
{
    public function __construct(
        public string $type,
        public string $title,
        public string $detail,
        public int $statusCode = 400,
        public array $meta = [],
    ) {}

    public static function entityNotFound(string $entityType, string $id): self
    {
        return new self(
            type: 'aurora:entity/not-found',
            title: 'Entity Not Found',
            detail: sprintf('%s "%s" does not exist.', ucfirst($entityType), $id),
            statusCode: 404,
        );
    }

    public static function accessDenied(string $operation, string $entityType, string $id): self
    {
        return new self(
            type: 'aurora:access/denied',
            title: 'Access Denied',
            detail: sprintf('You do not have permission to %s %s "%s".', $operation, $entityType, $id),
            statusCode: 403,
        );
    }

    public static function validationFailed(array $violations): self
    {
        return new self(
            type: 'aurora:validation/failed',
            title: 'Validation Failed',
            detail: sprintf('%d validation error(s) occurred.', count($violations)),
            statusCode: 422,
            meta: ['violations' => $violations],
        );
    }

    public static function translationMissing(string $entityType, string $id, string $langcode): self
    {
        return new self(
            type: 'aurora:i18n/translation-missing',
            title: 'Translation Missing',
            detail: sprintf('No %s translation exists for %s "%s".', $langcode, $entityType, $id),
            statusCode: 404,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'title' => $this->title,
            'detail' => $this->detail,
            'status' => $this->statusCode,
            'meta' => $this->meta ?: null,
        ]);
    }
}
