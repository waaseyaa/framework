<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling;

use RuntimeException;

/**
 * Deterministic, repository-owned changelog-fragment compiler.
 */
final class ChangelogFragments
{
    /** @var array<string, string> */
    public const TYPES = [
        'added' => 'Added',
        'changed' => 'Changed',
        'deprecated' => 'Deprecated',
        'removed' => 'Removed',
        'fixed' => 'Fixed',
        'security' => 'Security',
    ];

    /**
     * @return list<array{filename: string, identity: string, type: string, content: string, path: string}>
     */
    public static function load(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException("fragment directory does not exist: {$directory}");
        }

        $filenames = [];
        $iterator = new \DirectoryIterator($directory);
        foreach ($iterator as $file) {
            if ($file->isDot() || $file->getFilename() === '.gitkeep') {
                continue;
            }
            if ($file->isLink() || !$file->isFile()) {
                throw new RuntimeException("fragment entry must be a regular file: {$file->getFilename()}");
            }
            $filenames[] = $file->getFilename();
        }
        usort($filenames, 'strcmp');

        $fragments = [];
        $identities = [];
        $types = implode('|', array_keys(self::TYPES));
        foreach ($filenames as $filename) {
            if (preg_match('/^([1-9][0-9]*\.[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)*)\.(' . $types . ')\.md$/D', $filename, $matches) !== 1) {
                throw new RuntimeException(
                    "invalid fragment filename {$filename}; expected <issue>.<unique-slug>.<type>.md "
                    . '(types: ' . implode(', ', array_keys(self::TYPES)) . ')',
                );
            }
            $identity = $matches[1];
            $type = $matches[2];
            if (isset($identities[$identity])) {
                throw new RuntimeException(
                    "duplicate fragment identity {$identity}: {$identities[$identity]} and {$filename}",
                );
            }
            $identities[$identity] = $filename;

            $path = $directory . DIRECTORY_SEPARATOR . $filename;
            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException("cannot read fragment {$filename}");
            }
            self::validateContent($filename, $content);
            $fragments[] = compact('filename', 'identity', 'type', 'content', 'path');
        }

        return $fragments;
    }

    /**
     * @param list<array{filename: string, identity: string, type: string, content: string, path: string}> $fragments
     */
    public static function render(array $fragments): string
    {
        if ($fragments === []) {
            throw new RuntimeException('no pending changelog fragments; refusing an empty release');
        }

        $byType = array_fill_keys(array_keys(self::TYPES), []);
        foreach ($fragments as $fragment) {
            if (!isset($byType[$fragment['type']])) {
                throw new RuntimeException("unknown fragment type {$fragment['type']}");
            }
            $byType[$fragment['type']][] = $fragment;
        }

        $sections = [];
        foreach (self::TYPES as $type => $heading) {
            if ($byType[$type] === []) {
                continue;
            }
            usort(
                $byType[$type],
                static fn(array $left, array $right): int => strcmp($left['filename'], $right['filename']),
            );
            $sections[] = "### {$heading}\n\n"
                . implode("\n", array_column($byType[$type], 'content'));
        }

        return implode("\n", $sections);
    }

    public static function assemble(string $changelog, string $version, string $date, string $rendered): string
    {
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D', $version) !== 1) {
            throw new RuntimeException("invalid release version {$version}; expected bare SemVer without a leading v");
        }
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1) {
            throw new RuntimeException("invalid release date {$date}; expected YYYY-MM-DD");
        }
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            throw new RuntimeException("invalid release date {$date}; expected a real Gregorian date");
        }
        if (trim($rendered) === '') {
            throw new RuntimeException('rendered changelog fragments are empty; refusing an empty release');
        }
        if (str_contains($changelog, "## [{$version}]")) {
            throw new RuntimeException("CHANGELOG.md already contains release {$version}");
        }
        if (str_contains($changelog, "\r")) {
            throw new RuntimeException('CHANGELOG.md must use LF line endings');
        }

        preg_match_all('/^## \[Unreleased\]$/m', $changelog, $canonical);
        if (count($canonical[0]) !== 1) {
            throw new RuntimeException('CHANGELOG.md must contain exactly one canonical ## [Unreleased] heading');
        }
        if (preg_match('/^## \[Unreleased\]\n(.*?)(?=^## \[)/ms', $changelog, $pending) !== 1) {
            throw new RuntimeException('CHANGELOG.md must place a released section after ## [Unreleased]');
        }
        if (trim($pending[1]) !== '') {
            throw new RuntimeException(
                'CHANGELOG.md contains stale prose under [Unreleased]; migrate it to validated fragments before release',
            );
        }

        $replacement = "## [Unreleased]\n\n## [{$version}] - {$date}\n\n{$rendered}";

        return preg_replace(
            '/^## \[Unreleased\]\n.*?(?=^## \[)/ms',
            $replacement,
            $changelog,
            1,
        ) ?? throw new RuntimeException('failed to assemble CHANGELOG.md');
    }

    private static function validateContent(string $filename, string $content): void
    {
        if ($content === '') {
            throw new RuntimeException("fragment {$filename} is empty");
        }
        if (str_contains($content, "\r")) {
            throw new RuntimeException("fragment {$filename} must use LF line endings");
        }
        if (!str_ends_with($content, "\n")) {
            throw new RuntimeException("fragment {$filename} must end with exactly one LF newline");
        }
        if (str_ends_with($content, "\n\n")) {
            throw new RuntimeException("fragment {$filename} must not end with a blank line");
        }
        if (preg_match('//u', $content) !== 1) {
            throw new RuntimeException("fragment {$filename} is not valid UTF-8");
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            throw new RuntimeException("fragment {$filename} must not contain a UTF-8 BOM");
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content) === 1) {
            throw new RuntimeException("fragment {$filename} contains a forbidden control character");
        }

        $lines = explode("\n", substr($content, 0, -1));
        if (!str_starts_with($lines[0] ?? '', '- ')) {
            throw new RuntimeException("fragment {$filename} must begin with one Markdown list item ('- ')");
        }
        if (trim(substr($lines[0], 2)) === '') {
            throw new RuntimeException("fragment {$filename} list item must contain consumer-facing text");
        }
        /** @var null|array{character: string, length: int} $fence */
        $fence = null;
        foreach (array_slice($lines, 1) as $index => $line) {
            if (str_starts_with($line, '- ')) {
                throw new RuntimeException(
                    "fragment {$filename} contains a second top-level list item on line " . ($index + 2),
                );
            }
            if ($line !== '' && !str_starts_with($line, '  ')) {
                throw new RuntimeException(
                    "fragment {$filename} continuation line " . ($index + 2) . ' must be indented by two spaces',
                );
            }
            $continuation = str_starts_with($line, '  ') ? substr($line, 2) : $line;
            if ($fence === null && preg_match('/^(`{3,}|~{3,})/', $continuation, $opener) === 1) {
                $fence = ['character' => $opener[1][0], 'length' => strlen($opener[1])];
                continue;
            }
            if ($fence !== null && preg_match('/^([`~]+)[ \t]*$/', $continuation, $closer) === 1) {
                $run = $closer[1];
                if (strspn($run, $fence['character']) === strlen($run) && strlen($run) >= $fence['length']) {
                    $fence = null;
                    continue;
                }
            }
            if ($fence === null && str_starts_with(ltrim($line), '#')) {
                throw new RuntimeException("fragment {$filename} must not contain headings; the compiler owns taxonomy headings");
            }
        }
        if ($fence !== null) {
            throw new RuntimeException("fragment {$filename} contains an unclosed Markdown code fence");
        }
    }
}
