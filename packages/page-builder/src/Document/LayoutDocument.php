<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Document;

use Waaseyaa\PageBuilder\Document\Exception\InvalidLayoutDocumentException;

/**
 * Immutable, renderer-neutral representation of a governed page layout.
 *
 * Deep structural validation belongs to LayoutValidator. This boundary owns
 * the document envelope so unsupported authority cannot be silently ignored.
 */
/** @api */
final readonly class LayoutDocument
{
    public const SCHEMA = 'waaseyaa.layout';
    public const VERSION = 1;

    private const FIELDS = ['schema', 'version', 'template', 'sections'];

    /**
     * @param array{id: string, version: int} $template
     * @param list<array<string, mixed>>      $sections
     */
    private function __construct(
        private array $template,
        private array $sections,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        foreach (array_keys($payload) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                throw new InvalidLayoutDocumentException("Unknown layout document field: {$field}");
            }
        }

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new InvalidLayoutDocumentException("Missing layout document field: {$field}");
            }
        }

        if ($payload['schema'] !== self::SCHEMA) {
            throw new InvalidLayoutDocumentException('Unsupported layout document schema');
        }
        if (!is_int($payload['version']) || self::VERSION !== $payload['version']) {
            $version = is_scalar($payload['version']) ? (string) $payload['version'] : get_debug_type($payload['version']);
            throw new InvalidLayoutDocumentException("Unsupported layout document version: {$version}");
        }
        if (!is_array($payload['template'])) {
            throw new InvalidLayoutDocumentException('Layout document template must be an object');
        }
        if (['id', 'version'] !== array_keys($payload['template'])
            && ['version', 'id'] !== array_keys($payload['template'])) {
            throw new InvalidLayoutDocumentException('Layout document template must contain only id and version');
        }
        if (!is_string($payload['template']['id']) || '' === $payload['template']['id']) {
            throw new InvalidLayoutDocumentException('Layout document template id must be a non-empty string');
        }
        if (!is_int($payload['template']['version']) || $payload['template']['version'] < 1) {
            throw new InvalidLayoutDocumentException('Layout document template version must be a positive integer');
        }
        if (!is_array($payload['sections']) || !array_is_list($payload['sections'])) {
            throw new InvalidLayoutDocumentException('Layout document sections must be a list');
        }
        foreach ($payload['sections'] as $section) {
            if (!is_array($section)) {
                throw new InvalidLayoutDocumentException('Every layout document section must be an object');
            }
        }

        /** @var array{id: string, version: int} $template */
        $template = $payload['template'];
        /** @var list<array<string, mixed>> $sections */
        $sections = $payload['sections'];

        return new self($template, $sections);
    }

    /** @return array{id: string, version: int} */
    public function template(): array
    {
        return $this->template;
    }

    /** @return list<array<string, mixed>> */
    public function sections(): array
    {
        return $this->sections;
    }

    /** @return array{schema: string, version: int, template: array{id: string, version: int}, sections: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'template' => $this->template,
            'sections' => $this->sections,
        ];
    }
}
