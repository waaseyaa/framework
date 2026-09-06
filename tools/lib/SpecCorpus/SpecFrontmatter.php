<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling\SpecCorpus;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses optional YAML frontmatter and manifest metadata for spec lifecycle.
 *
 * @api
 */
final class SpecFrontmatter
{
    /**
     * @return array{
     *   lifecycle: SpecLifecycle,
     *   superseded_by: ?string,
     *   supersedes: ?string,
     *   declared_title: ?string,
     *   derived_title: ?string,
     *   declared: bool
     * }
     */
    public static function parse(string $content): array
    {
        if (!preg_match('/^---\r?\n/', $content)) {
            return self::undeclaredDefaults($content);
        }

        if (!preg_match('/^---\r?\n(.*?)\r?\n---\r?\n/s', $content, $match)) {
            throw new SpecCorpusException('Unclosed YAML frontmatter block.');
        }

        $yaml = $match[1];
        $body = substr($content, strlen($match[0]));
        $parsed = self::parseYaml($yaml);
        $spec = $parsed['waaseyaa-spec'] ?? null;

        if ($spec === null) {
            return self::undeclaredDefaults($body);
        }

        if (!is_array($spec)) {
            throw new SpecCorpusException('waaseyaa-spec must be a mapping.');
        }

        return self::parseDeclaredSpec($spec, $body);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{lifecycle: SpecLifecycle, superseded_by: ?string, supersedes: ?string, title: ?string}
     */
    public static function fromManifestEntry(array $entry): array
    {
        $lifecycleRaw = $entry['lifecycle'] ?? null;
        if (!is_string($lifecycleRaw) || $lifecycleRaw === '') {
            throw new SpecCorpusException('Manifest entry lifecycle is required.');
        }

        $supersededBy = $entry['superseded_by'] ?? null;
        if ($supersededBy !== null && !is_string($supersededBy)) {
            throw new SpecCorpusException('Manifest superseded_by must be a string document id.');
        }

        $supersedes = $entry['supersedes'] ?? null;
        if ($supersedes !== null && !is_string($supersedes)) {
            throw new SpecCorpusException('Manifest supersedes must be a string document id.');
        }

        $title = $entry['title'] ?? null;
        if ($title !== null && !is_string($title)) {
            throw new SpecCorpusException('Manifest title must be a string.');
        }

        if ($supersededBy !== null) {
            SpecCorpusGuard::assertDocumentId($supersededBy);
        }
        if ($supersedes !== null) {
            SpecCorpusGuard::assertDocumentId($supersedes);
        }

        return [
            'lifecycle' => SpecLifecycle::fromString($lifecycleRaw),
            'superseded_by' => $supersededBy,
            'supersedes' => $supersedes,
            'title' => $title,
        ];
    }

    public static function bodyWithoutFrontmatter(string $content): string
    {
        if (!preg_match('/^---\r?\n(.*?)\r?\n---\r?\n/s', $content, $match)) {
            return $content;
        }

        return substr($content, strlen($match[0]));
    }

    /**
     * @return array{
     *   lifecycle: SpecLifecycle,
     *   superseded_by: ?string,
     *   supersedes: ?string,
     *   declared_title: ?string,
     *   derived_title: ?string,
     *   declared: bool
     * }
     */
    private static function undeclaredDefaults(string $body): array
    {
        return [
            'lifecycle' => SpecLifecycle::Live,
            'superseded_by' => null,
            'supersedes' => null,
            'declared_title' => null,
            'derived_title' => self::extractTitle($body),
            'declared' => false,
        ];
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array{
     *   lifecycle: SpecLifecycle,
     *   superseded_by: ?string,
     *   supersedes: ?string,
     *   declared_title: ?string,
     *   derived_title: ?string,
     *   declared: bool
     * }
     */
    private static function parseDeclaredSpec(array $spec, string $body): array
    {
        $lifecycleRaw = $spec['lifecycle'] ?? null;
        if (!is_string($lifecycleRaw) || $lifecycleRaw === '') {
            throw new SpecCorpusException('waaseyaa-spec.lifecycle is required when waaseyaa-spec is present.');
        }

        $supersededBy = $spec['superseded_by'] ?? null;
        if ($supersededBy !== null && !is_string($supersededBy)) {
            throw new SpecCorpusException('waaseyaa-spec.superseded_by must be a string document id.');
        }

        $supersedes = $spec['supersedes'] ?? null;
        if ($supersedes !== null && !is_string($supersedes)) {
            throw new SpecCorpusException('waaseyaa-spec.supersedes must be a string document id.');
        }

        $declaredTitle = $spec['title'] ?? null;
        if ($declaredTitle !== null && !is_string($declaredTitle)) {
            throw new SpecCorpusException('waaseyaa-spec.title must be a string.');
        }

        if ($supersededBy !== null) {
            SpecCorpusGuard::assertDocumentId($supersededBy);
        }
        if ($supersedes !== null) {
            SpecCorpusGuard::assertDocumentId($supersedes);
        }

        return [
            'lifecycle' => SpecLifecycle::fromString($lifecycleRaw),
            'superseded_by' => $supersededBy,
            'supersedes' => $supersedes,
            'declared_title' => $declaredTitle,
            'derived_title' => self::extractTitle($body),
            'declared' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseYaml(string $yaml): array
    {
        try {
            $parsed = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            throw new SpecCorpusException('Invalid YAML frontmatter: ' . $e->getMessage(), 0, $e);
        }

        if ($parsed === null) {
            return [];
        }

        if (!is_array($parsed)) {
            throw new SpecCorpusException('Frontmatter YAML must be a mapping.');
        }

        return $parsed;
    }

    private static function extractTitle(string $body): ?string
    {
        if (preg_match('/^#\s+(.+)$/m', $body, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }
}
