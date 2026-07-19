<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Preflight;

/**
 * Canonical deployment identity of the complete field-classification document.
 *
 * Producer and consumer must bind the same whole decoded document into the
 * framework version. The normalized fields map is an inventory input, not the
 * classification artifact's identity.
 *
 * @internal
 */
final readonly class FieldAccessClassificationArtifact
{
    /** @param array<string, mixed> $document */
    private function __construct(
        public array $document,
        public string $identity,
    ) {}

    public static function load(string $projectRoot): self
    {
        $path = rtrim($projectRoot, '/') . '/.waaseyaa/field-access-classification.json';
        if (!is_file($path)) {
            return self::fromDocument([]);
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \UnexpectedValueException('Field-access classification artifact root must be an object.');
        }

        return self::fromDocument($decoded);
    }

    public function bindToFrameworkVersion(string $frameworkVersion): string
    {
        return $frameworkVersion . '@classification-' . substr($this->identity, 0, 16);
    }

    /** @param array<string, mixed> $document */
    private static function fromDocument(array $document): self
    {
        return new self(
            $document,
            hash('sha256', json_encode($document, JSON_THROW_ON_ERROR)),
        );
    }
}
