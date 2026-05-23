<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Parser for the framework's skill set (`skills/waaseyaa/<id>/SKILL.md`).
 *
 * Each skill lives in its own directory at the framework root. The
 * `SKILL.md` file uses Markdown with a leading YAML frontmatter block
 * delimited by `---` lines. This parser walks a base directory, reads
 * every `SKILL.md`, parses its frontmatter into a `ParsedSkill`, and
 * returns the list sorted by skill id for deterministic output.
 *
 * The frontmatter parser is intentionally tiny — it handles the
 * shape the framework produces (`key: value` pairs on single lines)
 * and nothing else. Bimaaji does not depend on `symfony/yaml`; adding
 * the dep purely for `SKILL.md` parsing would be over-engineering.
 *
 * @api
 */
final class SkillSetParser
{
    private const string FRONTMATTER_DELIMITER = '---';

    public function __construct(
        private readonly string $skillsDirectory,
    ) {}

    /**
     * Parse every SKILL.md file under one level of the skills directory
     * (one skill per subdirectory).
     *
     * @return list<ParsedSkill>
     */
    public function parse(): array
    {
        if (!is_dir($this->skillsDirectory)) {
            return [];
        }

        $skills = [];
        $entries = scandir($this->skillsDirectory);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $skillDir = $this->skillsDirectory . DIRECTORY_SEPARATOR . $entry;
            $skillFile = $skillDir . DIRECTORY_SEPARATOR . 'SKILL.md';

            if (!is_dir($skillDir) || !is_file($skillFile) || !is_readable($skillFile)) {
                continue;
            }

            $contents = file_get_contents($skillFile);
            if ($contents === false) {
                continue;
            }

            $parsed = $this->parseSkill($entry, $contents);
            if ($parsed !== null) {
                $skills[] = $parsed;
            }
        }

        usort($skills, static fn(ParsedSkill $a, ParsedSkill $b): int => strcmp($a->id, $b->id));

        return $skills;
    }

    private function parseSkill(string $id, string $contents): ?ParsedSkill
    {
        $contents = ltrim($contents);

        if (!str_starts_with($contents, self::FRONTMATTER_DELIMITER)) {
            // Missing frontmatter; treat the whole body as plain content
            // and use the directory id for the name + description.
            return new ParsedSkill(
                id: $id,
                name: $id,
                description: '',
                frontmatter: [],
                body: trim($contents),
            );
        }

        $afterOpening = substr($contents, strlen(self::FRONTMATTER_DELIMITER));
        $closingPosition = strpos($afterOpening, "\n" . self::FRONTMATTER_DELIMITER);
        if ($closingPosition === false) {
            return null;
        }

        $frontmatterRaw = trim(substr($afterOpening, 0, $closingPosition));
        $body = ltrim(substr($afterOpening, $closingPosition + strlen("\n" . self::FRONTMATTER_DELIMITER)));

        $frontmatter = $this->parseFrontmatter($frontmatterRaw);

        return new ParsedSkill(
            id: $id,
            name: $this->stringField($frontmatter, 'name', $id),
            description: $this->stringField($frontmatter, 'description', ''),
            frontmatter: $frontmatter,
            body: trim($body),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFrontmatter(string $raw): array
    {
        $result = [];
        $currentKey = null;

        foreach (explode("\n", $raw) as $rawLine) {
            $line = rtrim($rawLine, "\r");
            if ($line === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            // Continuation line (indented value spilling onto the next line).
            if ($currentKey !== null && str_starts_with($line, ' ')) {
                $existing = $result[$currentKey] ?? '';
                $result[$currentKey] = (is_string($existing) ? $existing : '') . ' ' . trim($line);
                continue;
            }

            $colonPosition = strpos($line, ':');
            if ($colonPosition === false) {
                continue;
            }

            $key = trim(substr($line, 0, $colonPosition));
            $value = trim(substr($line, $colonPosition + 1));

            if ($key === '') {
                continue;
            }

            $result[$key] = $this->coerceScalar($value);
            $currentKey = $key;
        }

        return $result;
    }

    private function coerceScalar(string $raw): string|bool|int|null
    {
        if ($raw === '' || $raw === '~' || strtolower($raw) === 'null') {
            return null;
        }

        $lower = strtolower($raw);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }

        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        // Strip surrounding quotes — but only if both ends match.
        if (strlen($raw) >= 2) {
            $first = $raw[0];
            $last = $raw[strlen($raw) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($raw, 1, -1);
            }
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function stringField(array $frontmatter, string $key, string $default): string
    {
        $value = $frontmatter[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
