<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Parser for the framework's canonical Agent Skill set.
 *
 * The skills ship as resources inside this package
 * (`resources/skills/<id>/SKILL.md`), so the same directory resolves in
 * the monorepo and in a consumer's `vendor/waaseyaa/bimaaji`. See
 * {@see PackagedSkillResources}. An application may point the parser
 * somewhere else with `bimaaji.skills_directory`.
 *
 * Each skill lives in its own directory. The `SKILL.md` file is Markdown
 * with a leading YAML frontmatter block delimited by `---` lines. This
 * parser walks one level of a base directory, reads every `SKILL.md`,
 * parses its frontmatter into a `ParsedSkill`, and returns the list
 * sorted by skill id for deterministic output.
 *
 * The frontmatter parser is intentionally tiny — it handles the
 * shape the framework produces (`key: value` pairs on single lines)
 * and nothing else. Bimaaji does not depend on `symfony/yaml`; adding
 * the dep purely for `SKILL.md` parsing would be over-engineering.
 *
 * Failures are loud, not silent. `parse()` used to return `[]` for a
 * missing directory and skip an unreadable document, which is how a
 * packaged consumer got `no skills discovered` with no way to tell an
 * absent install from one bad file (#2656). It now raises a
 * {@see SkillResourceException} that distinguishes the two.
 *
 * @api
 */
final class SkillSetParser
{
    private const string FRONTMATTER_DELIMITER = '---';

    /**
     * @param string $skillsDirectory Absolute path to a directory of `<skill-id>/SKILL.md` documents.
     * @param bool $configuredOverride True when the path came from `bimaaji.skills_directory` rather than the packaged default; only changes the wording of the failure diagnostic.
     */
    public function __construct(
        private readonly string $skillsDirectory,
        private readonly bool $configuredOverride = false,
    ) {}

    /**
     * The directory this parser reads. Exposed so diagnostics and tests
     * can name the resolved path rather than re-deriving it.
     */
    public function directory(): string
    {
        return $this->skillsDirectory;
    }

    /**
     * Parse every SKILL.md file under one level of the skills directory
     * (one skill per subdirectory).
     *
     * @return non-empty-list<ParsedSkill>
     * @throws SkillResourceException When the directory is missing/unreadable/empty, or a SKILL.md cannot be parsed.
     */
    public function parse(): array
    {
        if (!is_dir($this->skillsDirectory)) {
            throw SkillResourceException::missingDirectory($this->skillsDirectory, $this->configuredOverride);
        }

        $entries = scandir($this->skillsDirectory);
        if ($entries === false) {
            throw SkillResourceException::unreadableDirectory($this->skillsDirectory, $this->configuredOverride);
        }

        $skills = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $skillDir = $this->skillsDirectory . DIRECTORY_SEPARATOR . $entry;
            $skillFile = $skillDir . DIRECTORY_SEPARATOR . 'SKILL.md';

            if (!is_dir($skillDir) || !is_file($skillFile)) {
                continue;
            }

            if (!is_readable($skillFile)) {
                throw SkillResourceException::corruptSkill($this->skillsDirectory, $skillFile, 'the file is not readable');
            }

            $contents = file_get_contents($skillFile);
            if ($contents === false) {
                throw SkillResourceException::corruptSkill($this->skillsDirectory, $skillFile, 'the file could not be read');
            }

            $skills[] = $this->parseSkill($entry, $contents, $skillFile, hash('sha256', $contents));
        }

        if ($skills === []) {
            throw SkillResourceException::emptyDirectory($this->skillsDirectory, $this->configuredOverride);
        }

        usort($skills, static fn(ParsedSkill $a, ParsedSkill $b): int => strcmp($a->id, $b->id));

        return $skills;
    }

    private function parseSkill(string $id, string $contents, string $file, string $sourceSha256): ParsedSkill
    {
        $contents = ltrim($contents);

        if ($contents === '') {
            throw SkillResourceException::corruptSkill($this->skillsDirectory, $file, 'the document is empty');
        }

        if (!str_starts_with($contents, self::FRONTMATTER_DELIMITER)) {
            // Missing frontmatter; treat the whole body as plain content
            // and use the directory id for the name + description.
            return new ParsedSkill(
                id: $id,
                name: $id,
                description: '',
                frontmatter: [],
                body: trim($contents),
                sourceSha256: $sourceSha256,
            );
        }

        $afterOpening = substr($contents, strlen(self::FRONTMATTER_DELIMITER));
        $closingPosition = strpos($afterOpening, "\n" . self::FRONTMATTER_DELIMITER);
        if ($closingPosition === false) {
            throw SkillResourceException::corruptSkill(
                $this->skillsDirectory,
                $file,
                'the YAML frontmatter block opens with `---` but is never closed',
            );
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
            sourceSha256: $sourceSha256,
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
