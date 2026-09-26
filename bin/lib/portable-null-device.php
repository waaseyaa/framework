<?php

declare(strict_types=1);

require_once __DIR__ . '/repository-files.php';

/**
 * Portable null-device guard (FW-2678-PORTABLE-NULL-DEVICE-03, #2678).
 *
 * A hard-coded "/dev/null" in PHP is a POSIX path. As a proc_open()
 * descriptor it cannot open on native Windows, and proc_open() then returns
 * false, which callers have read as "the program is missing" (#2647). In a
 * host-shell string, cmd.exe cannot open it either, so the command never runs
 * (#3096). The host-aware forms are PHP's own ['null'] descriptor and a
 * host-derived choice (`PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'`).
 *
 * The guard is static. It tokenizes every governed production PHP file and
 * inspects each string token that spells "/dev/null" (comments are
 * documentation and are ignored):
 *
 * - a direct descriptor, `['file', '/dev/null', ...]`, is always rejected,
 *   whether or not it is classified, even inside a host choice or spelled
 *   inside a string of PHP code;
 * - every other occurrence must be classified in
 *   tools/portable-null-device-classifications.json by file, enclosing symbol
 *   and literal, with the exact occurrence count and one purpose. The anchor
 *   has no line number, so an unrelated edit cannot make it stale. The
 *   purposes are mutually exclusive syntactic shapes of the occurrence and its
 *   statement, so a classification can only claim the purpose whose shape the
 *   occurrence has:
 *   - platform-derived: the same statement also names the Windows NUL device
 *     and a Windows host signal (the direction of the choice is not checked,
 *     and a choice spread over several statements does not fit);
 *   - semantic-diff-marker: unified-diff data (a "--- /dev/null" or
 *     "+++ /dev/null" header, even in a patch whose added lines redirect, or
 *     a bare "/dev/null" label next to an a/ or b/ label), never opened;
 *   - posix-only-shell: shell command text, not a diff header, that
 *     redirects to /dev/null or, in text of more than one word, passes it as
 *     a whitespace- or `=`-delimited word (quoted only as a whole word), with
 *     no NUL counterpart, in code the classification declares POSIX-only.
 *
 * Stale, duplicated, malformed, unsorted or overly broad classifications are
 * rejected. Line endings are normalized first, so a CRLF checkout scans
 * exactly as an LF one. Plain functions, no autoloader and no extension
 * beyond PHP's default build: the gate runs before `composer install` as well
 * as after it.
 */

const PND_SCHEMA = 'waaseyaa.portable_null_device_classifications';
const PND_SCHEMA_VERSION = 1;
const PND_MANIFEST = 'tools/portable-null-device-classifications.json';

/**
 * The POSIX null device. This file is on the governed surface like any other
 * and never opens the device, so it spells the path by construction and
 * holds no literal it would have to classify.
 */
const PND_NULL_DEVICE = '/dev/' . 'null';

const PND_EXIT_PASS = 0;
const PND_EXIT_VIOLATION = 1;
const PND_EXIT_HARNESS = 2;

/** The classification vocabulary, in canonical order. */
const PND_PURPOSES = ['platform-derived', 'semantic-diff-marker', 'posix-only-shell'];

const PND_MANIFEST_KEYS = ['schema', 'schema_version', 'change_record', 'statement', 'purposes', 'classifications'];
const PND_ENTRY_KEYS = ['file', 'symbol', 'literal', 'occurrences', 'purpose', 'rationale'];

const PND_RATIONALE_MAX = 500;

/** Diagnostics survive a literal that is not valid UTF-8. */
const PND_JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

/**
 * Whether a repository-relative path is on the governed production surface.
 *
 * Excluded: tests and their support code (root `tests/`, `packages/<pkg>/tests/`,
 * the autoload-dev `packages/<pkg>/testing/`, `packages/<pkg>/e2e/`,
 * `skeleton/tests/`), `benchmarks/` (test scope, as
 * SubprocessHarnessContractTest defines it), documentation (`docs/`) and
 * archived mission records (`kitty-specs/`). Vendor dependencies are never
 * enumerated because Git ignores them; a `vendor/` segment is refused too.
 */
function pnd_is_governed_path(string $path): bool
{
    return preg_match('#(?:^|/)vendor/#', $path) !== 1
        && preg_match('#^(?:tests|benchmarks|docs|kitty-specs)/#', $path) !== 1
        && preg_match('#^packages/[^/]+/(?:tests|testing|e2e)/#', $path) !== 1
        && preg_match('#^skeleton/tests/#', $path) !== 1;
}

/**
 * A `.php` file, or any file whose content opens as PHP: an open tag first
 * (in any case, after an optional byte-order mark), or a PHP shebang line
 * (`php`, `php8.5`, ...) followed, after any blank lines, by the open tag.
 * $head is at least the first 256 bytes of the file.
 */
function pnd_is_php_source(string $path, string $head): bool
{
    if (str_ends_with($path, '.php')) {
        return true;
    }
    $head = str_replace("\r\n", "\n", str_starts_with($head, "\u{FEFF}") ? substr($head, 3) : $head);

    return stripos($head, '<?php') === 0
        || preg_match('/\A#![^\n]*\bphp[\d.]*\b[^\n]*\n(?:[ \t]*\n)*<\?php/i', $head) === 1;
}

/**
 * Enumerate the governed PHP files under $root through Git (tracked plus
 * untracked, unignored files; see repositoryFiles()).
 *
 * @return array{files: list<string>, sources: array<string, string>} every
 *   governed PHP path, and the LF-normalized source of each one that spells
 *   /dev/null anywhere
 *
 * @throws RuntimeException when Git cannot enumerate or a governed file cannot be read
 */
function pnd_governed_sources(string $root): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $files = [];
    $sources = [];
    foreach (repositoryFiles($root) as $path) {
        if (!pnd_is_governed_path($path)) {
            continue;
        }
        $absolute = $root . '/' . $path;
        $head = str_ends_with($path, '.php') ? '' : @file_get_contents($absolute, false, null, 0, 256);
        if (!is_string($head)) {
            throw new RuntimeException("cannot read {$path}");
        }
        if (!pnd_is_php_source($path, $head)) {
            continue;
        }
        $source = @file_get_contents($absolute);
        if (!is_string($source)) {
            throw new RuntimeException("cannot read {$path}");
        }
        $files[] = $path;
        if (str_contains($source, PND_NULL_DEVICE)) {
            $sources[$path] = str_replace("\r\n", "\n", $source);
        }
    }

    return ['files' => $files, 'sources' => $sources];
}

/**
 * The content of a string token as written between its delimiters: quotes
 * are stripped from a constant string, escape sequences are not decoded, and
 * interpolated or heredoc fragments are returned as they are.
 *
 * @param array{0: int, 1: string, 2: int} $token
 */
function pnd_token_content(array $token): string
{
    if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
        return $token[1];
    }
    $text = $token[1];
    if ($text[0] === 'b' || $text[0] === 'B') {
        $text = substr($text, 1);
    }

    return substr($text, 1, -1);
}

/**
 * Every string token in $source that spells /dev/null, with its enclosing
 * symbol, whether it is a direct proc_open() file descriptor, and the facts
 * of the token and its statement that decide which purpose can fit it.
 *
 * The symbol is `Class::method`, `function`, `Class` (a class-level
 * constant or property) or `{main}` (file scope). An anonymous class is
 * `class@anonymous`. Closures and arrow functions belong to the symbol that
 * encloses them.
 *
 * @return list<array{file: string, line: int, symbol: string, literal: string, descriptor: bool,
 *     redirection: bool, shell_word: bool, diff_header: bool, bare: bool, nul: bool, windows: bool, diff_label: bool}>
 */
function pnd_occurrences(string $path, string $source): array
{
    $source = str_replace("\r\n", "\n", $source);
    if (!str_contains($source, PND_NULL_DEVICE)) {
        return [];
    }

    $tokens = token_get_all($source);
    $frames = [];
    $pending = null;
    $statement = 0;
    $statementOf = [];
    $significant = [];
    $candidates = [];
    foreach ($tokens as $index => $token) {
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        $statementOf[$index] = $statement;
        if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $previous = $significant === [] ? null : $tokens[$significant[array_key_last($significant)]];
        $significant[] = $index;

        if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)
            && str_contains($text, PND_NULL_DEVICE)) {
            $candidates[] = [$index, count($significant) - 1, pnd_symbol($frames)];
        }

        // The token after a declaration keyword names it. A colon there means
        // the keyword was a named argument (`class:`, `function:`), and a class
        // keyword followed by anything but a name declares an anonymous class.
        // A function's name declares nothing itself, even when it is a keyword
        // (`function class()`, `function function()`).
        if ($pending !== null && $pending['name'] === null && $pending['kind'] !== 'closure' && $text !== '&') {
            if ($text === ':') {
                $pending = null;
            } elseif ($pending['kind'] === 'class') {
                $pending['name'] = $id === T_STRING ? $text : 'class@anonymous';
            } elseif ($text === '(') {
                $pending['kind'] = 'closure';
            } else {
                $pending['name'] = $text;
                continue;
            }
        }

        if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
            && !(is_array($previous) && $previous[0] === T_DOUBLE_COLON)) {
            $pending = ['kind' => 'class', 'name' => null];
        } elseif ($id === T_FUNCTION) {
            $pending = ['kind' => 'function', 'name' => null];
        } elseif ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $frames[] = ['kind' => 'interpolation', 'name' => null];
        } elseif ($text === '{') {
            $frames[] = $pending ?? ['kind' => 'block', 'name' => null];
            $pending = null;
            $statement++;
        } elseif ($text === '}') {
            $closed = array_pop($frames);
            if ($closed === null || $closed['kind'] !== 'interpolation') {
                $statement++;
            }
        } elseif ($text === ';') {
            $pending = null;
            $statement++;
        } elseif (in_array($id, [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG], true)) {
            $statement++;
        }
    }

    // A redirection (`2>`, `>`, `>>`, `&>`, `<`, never PHP's `=>` or `->`),
    // a whitespace- or `=`-delimited word of command text, or a unified-diff
    // header line (`--- ` or `+++ `, at the start or after a real or escaped
    // newline). Command text may quote the path when the same quote,
    // optionally escaped, closes right after it, and a quoted word must then
    // end the word (whitespace, a pipe or `&`, or the end); PHP or JSON text
    // inside a string (`, '/dev/null']`, `"stdin": "/dev/null"}`) is
    // therefore not command text.
    $spelling = preg_quote(PND_NULL_DEVICE, '#');
    $device = $spelling . '(?![\w/.-])';
    $quoted = '(?<quote>\\\\?[\'"])' . $spelling . '\k<quote>';
    $redirection = '#(?<![=-])(?:[0-9&]?>{1,2}|<)\s*(?:' . $quoted . '|' . $device . ')#';
    $shellWord = '#(?:^|[\s=])(?:' . $quoted . '(?=[\s|&]|$)|' . $device . ')#';
    $diffHeader = '#(?:^|\n|\\\\n)(?:---|\+\+\+) ' . $device . '#';
    // A descriptor spelled inside a string (PHP code for `php -r`, or code a
    // generator writes) is a direct descriptor all the same.
    $embeddedDescriptor = '#(?<type>\\\\?[\'"])file\k<type>\s*,\s*(?:[0-9]+\s*=>\s*)?' . $quoted . '#';

    $occurrences = [];
    $facts = [];
    foreach ($candidates as [$index, $position, $symbol]) {
        $token = $tokens[$index];
        $content = pnd_token_content($token);
        $statementId = $statementOf[$index];
        $facts[$statementId] ??= pnd_statement_facts($tokens, $statementOf, $statementId);
        $occurrences[] = [
            'file' => $path,
            'line' => $token[2],
            'symbol' => $symbol,
            'literal' => $content,
            'descriptor' => pnd_is_direct_descriptor($tokens, $significant, $position, $content)
                || preg_match($embeddedDescriptor, $content) === 1,
            'redirection' => preg_match($redirection, $content) === 1,
            // Command text has more than one word; `key=/dev/null` alone is an
            // argument or environment value that never reaches a shell.
            'shell_word' => preg_match('/\s/', $content) === 1 && preg_match($shellWord, $content) === 1,
            'diff_header' => preg_match($diffHeader, $content) === 1,
            'bare' => $content === PND_NULL_DEVICE,
            ...$facts[$statementId],
        ];
    }

    return $occurrences;
}

/**
 * @param list<array{kind: string, name: ?string}> $frames
 */
function pnd_symbol(array $frames): string
{
    $function = null;
    $class = null;
    for ($index = count($frames) - 1; $index >= 0; $index--) {
        if ($function === null && $frames[$index]['kind'] === 'function') {
            $function = (string) $frames[$index]['name'];
        } elseif ($frames[$index]['kind'] === 'class') {
            $class = (string) $frames[$index]['name'];
            break;
        }
    }
    if ($function !== null) {
        return $class === null ? $function : $class . '::' . $function;
    }

    return $class ?? '{main}';
}

/**
 * `['file', '/dev/null', ...]` or `array('file', '/dev/null', ...)`,
 * positional or with keys: the path element of a proc_open() file descriptor
 * is the bare literal.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 * @param list<int> $significant
 */
function pnd_is_direct_descriptor(array $tokens, array $significant, int $position, string $content): bool
{
    if ($content !== PND_NULL_DEVICE) {
        return false;
    }
    $at = static fn(int $offset): array|string|null => $tokens[$significant[$position + $offset] ?? -1] ?? null;
    $text = static fn(array|string|null $token): ?string => is_array($token) ? $token[1] : $token;

    if (!in_array($text($at(1)), [',', ']', ')'], true)) {
        return false;
    }
    $offset = -1;
    if ($text($at($offset)) === '=>') {
        $offset -= 2;
    }
    if ($text($at($offset)) !== ',') {
        return false;
    }
    $type = $at($offset - 1);
    if (!is_array($type) || $type[0] !== T_CONSTANT_ENCAPSED_STRING || pnd_token_content($type) !== 'file') {
        return false;
    }
    $offset -= 2;
    if ($text($at($offset)) === '=>') {
        $offset -= 2;
    }
    $open = $text($at($offset));
    $before = $at($offset - 1);

    return $open === '[' || ($open === '(' && is_array($before) && $before[0] === T_ARRAY);
}

/**
 * What the statement around an occurrence says about the host:
 * - nul: another string token names the Windows null device (NUL as a word);
 * - windows: a host signal (PHP_OS_FAMILY, PHP_OS, DIRECTORY_SEPARATOR, a
 *   Windows-named identifier or a string naming Windows);
 * - diff_label: a string token is a unified-diff side label (a/..., b/...).
 *
 * A statement ends at `;`, at a block brace and at an open or close tag;
 * interpolation braces inside a string do not end it.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 * @param array<int, int> $statementOf
 *
 * @return array{nul: bool, windows: bool, diff_label: bool}
 */
function pnd_statement_facts(array $tokens, array $statementOf, int $statementId): array
{
    $facts = ['nul' => false, 'windows' => false, 'diff_label' => false];
    foreach ($statementOf as $index => $id) {
        if ($id !== $statementId || !is_array($tokens[$index])) {
            continue;
        }
        [$kind, $text] = $tokens[$index];
        if (in_array($kind, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $content = pnd_token_content($tokens[$index]);
            $facts['nul'] = $facts['nul'] || preg_match('/(?<![\w\/])nul(?!\w)/i', $content) === 1;
            $facts['windows'] = $facts['windows'] || stripos($content, 'windows') !== false;
            $facts['diff_label'] = $facts['diff_label'] || preg_match('#^[ab]/#', $content) === 1;
        } elseif (in_array($kind, [T_STRING, T_NAME_FULLY_QUALIFIED, T_VARIABLE], true)) {
            $name = ltrim($text, '\\$');
            $facts['windows'] = $facts['windows']
                || in_array($name, ['PHP_OS_FAMILY', 'PHP_OS', 'DIRECTORY_SEPARATOR'], true)
                || stripos($name, 'windows') !== false;
        }
    }

    return $facts;
}

/**
 * Why an occurrence cannot carry $purpose, or null when it fits. The three
 * shapes are mutually exclusive, so at most one purpose fits an occurrence.
 *
 * @param array{redirection: bool, shell_word: bool, diff_header: bool, bare: bool, nul: bool, windows: bool, diff_label: bool} $occurrence
 */
function pnd_purpose_misfit(string $purpose, array $occurrence): ?string
{
    $device = PND_NULL_DEVICE;

    return match ($purpose) {
        'platform-derived' => $occurrence['nul'] && $occurrence['windows']
            ? null
            : 'its statement does not also name the Windows NUL device and a Windows host signal',
        'semantic-diff-marker' => match (true) {
            $occurrence['nul'] => 'its statement names the Windows NUL device, so it is a host choice, not diff data',
            // A patch is diff data even when a line it adds redirects.
            $occurrence['diff_header'] || ($occurrence['bare'] && $occurrence['diff_label']) => null,
            $occurrence['redirection'] => 'it is a shell redirection, not diff data',
            default => "it is neither a \"--- {$device}\" or \"+++ {$device}\" diff header nor a bare {$device} label beside an a/ or b/ label",
        },
        'posix-only-shell' => match (true) {
            $occurrence['nul'] => 'its statement names the Windows NUL device, so it is a host choice, not a POSIX-only fragment',
            $occurrence['diff_header'] => 'it is a unified-diff header, not a shell fragment',
            $occurrence['redirection'] || $occurrence['shell_word'] => null,
            default => "it neither redirects to {$device} nor passes it as a word of a shell command",
        },
        default => 'the purpose is not one of ' . implode(', ', PND_PURPOSES),
    };
}

/**
 * The purpose whose shape the occurrence has, if any.
 *
 * @param array{redirection: bool, shell_word: bool, diff_header: bool, bare: bool, nul: bool, windows: bool, diff_label: bool} $occurrence
 */
function pnd_fitting_purpose(array $occurrence): ?string
{
    foreach (PND_PURPOSES as $purpose) {
        if (pnd_purpose_misfit($purpose, $occurrence) === null) {
            return $purpose;
        }
    }

    return null;
}

/**
 * Validate the classification manifest against the governed files.
 *
 * @param list<string> $files governed PHP paths
 *
 * @return array{entries: list<array{index: int, file: string, symbol: string, literal: string, occurrences: int, purpose: string}>,
 *     violations: list<array<string, mixed>>}
 */
function pnd_validate_manifest(mixed $manifest, array $files): array
{
    $violations = [];
    $invalid = static function (string $kind, string $message, ?int $index = null) use (&$violations): void {
        $violations[] = ['kind' => $kind, 'entry' => $index, 'message' => $message];
    };
    if (!is_array($manifest) || array_is_list($manifest) && $manifest !== []) {
        $invalid('malformed', 'the manifest must be a JSON object');

        return ['entries' => [], 'violations' => $violations];
    }
    $keys = array_keys($manifest);
    if (array_diff(PND_MANIFEST_KEYS, $keys) !== [] || array_diff($keys, PND_MANIFEST_KEYS) !== []) {
        $invalid('malformed', 'the manifest must have exactly the keys ' . implode(', ', PND_MANIFEST_KEYS));
    }
    if (($manifest['schema'] ?? null) !== PND_SCHEMA || ($manifest['schema_version'] ?? null) !== PND_SCHEMA_VERSION) {
        $invalid('malformed', sprintf('schema must be %s version %d', PND_SCHEMA, PND_SCHEMA_VERSION));
    }
    foreach (['change_record', 'statement'] as $key) {
        if (!is_string($manifest[$key] ?? null) || trim($manifest[$key]) === '') {
            $invalid('malformed', "{$key} must be a non-empty string");
        }
    }
    $purposes = $manifest['purposes'] ?? null;
    if (!is_array($purposes) || array_keys($purposes) !== PND_PURPOSES
        || array_filter($purposes, static fn(mixed $text): bool => !is_string($text) || trim($text) === '') !== []) {
        $invalid('malformed', 'purposes must define exactly ' . implode(', ', PND_PURPOSES) . ', in that order, each with a description');
    }
    $classifications = $manifest['classifications'] ?? null;
    if (!is_array($classifications) || !array_is_list($classifications)) {
        $invalid('malformed', 'classifications must be a list');

        return ['entries' => [], 'violations' => $violations];
    }

    $governed = array_fill_keys($files, true);
    $entries = [];
    $seen = [];
    $previousKey = null;
    foreach ($classifications as $index => $entry) {
        if (!is_array($entry) || array_is_list($entry)) {
            $invalid('malformed', 'a classification must be an object', $index);
            continue;
        }
        $keys = array_keys($entry);
        if (array_diff(PND_ENTRY_KEYS, $keys) !== [] || array_diff($keys, PND_ENTRY_KEYS) !== []) {
            $invalid('malformed', 'a classification must have exactly the keys ' . implode(', ', PND_ENTRY_KEYS), $index);
            continue;
        }
        $file = $entry['file'];
        $symbol = $entry['symbol'];
        $literal = $entry['literal'];
        $count = $entry['occurrences'];
        $purpose = $entry['purpose'];
        $rationale = $entry['rationale'];
        $problems = [];
        $broad = [];
        if (!is_string($file) || $file === '') {
            $problems[] = 'file must be a non-empty string';
        } elseif (strpbrk($file, '*?[]{}') !== false || str_ends_with($file, '/')
            || array_filter($files, static fn(string $path): bool => str_starts_with($path, $file . '/')) !== []) {
            $broad[] = "file {$file} is a pattern or a directory; name exactly one file";
        } elseif (str_contains($file, '\\') || str_starts_with($file, '/') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $file) === 1
            || preg_match('/^[A-Za-z]:/', $file) === 1) {
            $problems[] = "file {$file} must be a repository-relative path with / separators";
        } elseif (!pnd_is_governed_path($file)) {
            $problems[] = "file {$file} is outside the governed surface (tests, benchmarks, docs, kitty-specs and vendor are not classified)";
        }
        if (!is_string($symbol) || $symbol === '') {
            $problems[] = 'symbol must be a non-empty string';
        } elseif (strpbrk($symbol, '*?[]') !== false) {
            $broad[] = "symbol {$symbol} is a pattern; name exactly one symbol";
        } elseif (preg_match('/^(?:\{main\}|(?:[A-Za-z_\x80-\xff][\w\x80-\xff]*|class@anonymous)(?:::[A-Za-z_\x80-\xff][\w\x80-\xff]*)?)$/', $symbol) !== 1) {
            $problems[] = "symbol {$symbol} must be {main}, a function, a class, or Class::method";
        }
        if (!is_string($literal) || !str_contains($literal, PND_NULL_DEVICE)) {
            $problems[] = 'literal must be the content of a string token that spells ' . PND_NULL_DEVICE;
        }
        if (!is_int($count) || $count < 1) {
            $problems[] = 'occurrences must be a positive integer';
        }
        if (!is_string($purpose) || !in_array($purpose, PND_PURPOSES, true)) {
            $problems[] = 'purpose must be one of ' . implode(', ', PND_PURPOSES);
        }
        if (!is_string($rationale) || trim($rationale) === ''
            || preg_match('/\A[^\r\n]{1,' . PND_RATIONALE_MAX . '}\z/u', $rationale) !== 1) {
            $problems[] = 'rationale must be one non-empty line of at most ' . PND_RATIONALE_MAX . ' characters';
        }
        foreach ($broad as $message) {
            $invalid('overly-broad', $message, $index);
        }
        foreach ($problems as $message) {
            $invalid('malformed', $message, $index);
        }
        if ($broad !== [] || $problems !== []) {
            continue;
        }

        $key = [$file, $symbol, $literal];
        if (isset($seen[pnd_key($file, $symbol, $literal)])) {
            $invalid('duplicate', "classifies {$file} {$symbol} " . json_encode($literal, PND_JSON) . ' again', $index);
            continue;
        }
        $seen[pnd_key($file, $symbol, $literal)] = true;
        if ($previousKey !== null && (strcmp($file, $previousKey[0]) ?: strcmp($symbol, $previousKey[1]) ?: strcmp($literal, $previousKey[2])) < 0) {
            $invalid('malformed', 'classifications must be sorted by file, then symbol, then literal (byte order)', $index);
        }
        $previousKey = $key;
        if (!isset($governed[$file])) {
            $invalid('stale', "file {$file} is not a governed PHP file in this checkout", $index);
            continue;
        }
        $entries[] = ['index' => $index, 'file' => $file, 'symbol' => $symbol, 'literal' => $literal, 'occurrences' => $count, 'purpose' => $purpose];
    }

    return ['entries' => $entries, 'violations' => $violations];
}

/**
 * Match every occurrence against the classifications.
 *
 * An unclassified occurrence carries a `suggestion`: the manifest change that
 * would classify it, or why none can.
 * - Without an entry for its file, symbol and literal, the first occurrence
 *   gets one new entry covering all of them (`add`), or `host` when no single
 *   purpose fits them, or `unencodable` when the literal is not UTF-8.
 * - A surplus over an existing entry that fits its purpose gets one higher
 *   count for that entry (`raise`), on the first such occurrence. A surplus
 *   that misfits gets `conflict` when it fits another purpose (one
 *   classification per file, symbol and literal) and `misfit` otherwise.
 * - Every other occurrence points at the line whose suggestion covers it
 *   (`see`), which is never below it.
 *
 * @param list<string> $files governed PHP paths
 * @param array<string, string> $sources path => source, for every governed
 *   file that spells /dev/null
 *
 * @return array{violations: list<array<string, mixed>>, classified: array<string, int>, files: int}
 */
function pnd_analyze(array $files, array $sources, mixed $manifest): array
{
    ['entries' => $entries, 'violations' => $violations] = pnd_validate_manifest($manifest, $files);

    $groups = [];
    $descriptorKeys = [];
    ksort($sources, SORT_STRING);
    foreach ($sources as $path => $source) {
        foreach (pnd_occurrences($path, $source) as $occurrence) {
            $key = pnd_key($occurrence['file'], $occurrence['symbol'], $occurrence['literal']);
            if ($occurrence['descriptor']) {
                $descriptorKeys[$key] = true;
                $violations[] = [
                    'kind' => 'direct-descriptor',
                    'occurrence' => $occurrence,
                    'message' => 'a proc_open() file descriptor must not hard-code ' . PND_NULL_DEVICE
                        . ', which native Windows cannot open, not even inside a host choice; no classification can accept one. '
                        . "Use the ['null'] descriptor, which PHP opens as the host's null device.",
                ];
                continue;
            }
            $groups[$key][] = $occurrence;
        }
    }

    $classified = array_fill_keys(PND_PURPOSES, 0);
    foreach ($entries as $entry) {
        $key = pnd_key($entry['file'], $entry['symbol'], $entry['literal']);
        $found = $groups[$key] ?? [];
        unset($groups[$key]);
        $label = "{$entry['file']} {$entry['symbol']} " . json_encode($entry['literal'], PND_JSON);
        if ($found === []) {
            $violations[] = [
                'kind' => 'stale',
                'entry' => $entry['index'],
                'message' => isset($descriptorKeys[$key])
                    ? "classifies a direct descriptor ({$label}), which cannot be classified"
                    : "classifies {$label}, which no longer occurs; remove or update the classification",
            ];
            continue;
        }
        if (count($found) < $entry['occurrences']) {
            $violations[] = [
                'kind' => 'stale',
                'entry' => $entry['index'],
                'message' => sprintf('declares %d occurrence(s) of %s, found %d: a classified occurrence was removed', $entry['occurrences'], $label, count($found)),
            ];
        }
        // The classification covers the occurrences that fit its purpose
        // first (in source order), so a surplus is reported where it misfits.
        usort($found, static fn(array $left, array $right): int => (pnd_purpose_misfit($entry['purpose'], $left) !== null)
            <=> (pnd_purpose_misfit($entry['purpose'], $right) !== null));
        $extras = array_slice($found, $entry['occurrences']);
        $fitting = count(array_filter($extras, static fn(array $extra): bool => pnd_purpose_misfit($entry['purpose'], $extra) === null));
        $message = sprintf('%d occurrences of this literal in this symbol, %d classified', count($found), $entry['occurrences']);
        $raisedAt = null;
        foreach ($extras as $extra) {
            $misfit = pnd_purpose_misfit($entry['purpose'], $extra);
            if ($misfit === null) {
                // Every surplus that fits: one raise, on the first of them.
                $suggestion = $raisedAt === null
                    ? ['raise' => $entry['index'], 'from' => $entry['occurrences'], 'to' => $entry['occurrences'] + $fitting]
                    : ['see' => $raisedAt];
                $raisedAt ??= $extra['line'];
            } else {
                // One classification per file, symbol and literal: a surplus
                // of another shape cannot join it, whatever that shape is.
                $other = pnd_fitting_purpose($extra);
                $suggestion = $other !== null
                    ? ['conflict' => $entry['index'], 'purpose' => $entry['purpose'], 'fits' => $other]
                    : ['misfit' => $entry['purpose'], 'reason' => $misfit];
            }
            $violations[] = [
                'kind' => 'unclassified',
                'entry' => $entry['index'],
                'occurrence' => $extra,
                'message' => $message,
                'suggestion' => $suggestion,
            ];
        }
        foreach (array_slice($found, 0, $entry['occurrences']) as $occurrence) {
            $misfit = pnd_purpose_misfit($entry['purpose'], $occurrence);
            if ($misfit !== null) {
                $violations[] = [
                    'kind' => 'purpose-mismatch',
                    'entry' => $entry['index'],
                    'occurrence' => $occurrence,
                    'message' => "classified {$entry['purpose']}, but {$misfit}",
                ];
            } else {
                $classified[$entry['purpose']]++;
            }
        }
    }
    foreach ($groups as $occurrences) {
        $fits = array_values(array_unique(array_map(static fn(array $occurrence): string => pnd_fitting_purpose($occurrence) ?? '', $occurrences)));
        $first = $occurrences[0];
        $suggestion = match (true) {
            // The JSON manifest cannot hold a literal that is not UTF-8.
            preg_match('//u', $first['literal']) !== 1 => ['unencodable' => true],
            count($fits) === 1 && $fits[0] !== '' => ['add' => [
                'file' => $first['file'],
                'symbol' => $first['symbol'],
                'literal' => $first['literal'],
                'occurrences' => count($occurrences),
                'purpose' => $fits[0],
            ]],
            default => ['host' => count($fits) > 1],
        };
        foreach ($occurrences as $position => $occurrence) {
            $violations[] = [
                'kind' => 'unclassified',
                'occurrence' => $occurrence,
                'message' => 'no classification covers this literal',
                'suggestion' => $position === 0 ? $suggestion : ['see' => $first['line']],
            ];
        }
    }

    usort($violations, static fn(array $left, array $right): int => pnd_sort_key($left) <=> pnd_sort_key($right));

    return ['violations' => $violations, 'classified' => $classified, 'files' => count($files)];
}

/** How to take the device from the host instead of hard-coding it. */
function pnd_host_derived_hint(): string
{
    return "Take the device from the host: the ['null'] descriptor (PHP opens the host's null device), "
        . "or PHP_OS_FAMILY === 'Windows' ? 'NUL' : '" . PND_NULL_DEVICE . "'.";
}

/** File and symbol cannot contain a NUL byte, so the key is unambiguous for any literal. */
function pnd_key(string $file, string $symbol, string $literal): string
{
    return $file . "\0" . $symbol . "\0" . $literal;
}

/**
 * @param array<string, mixed> $violation
 *
 * @return array{0: int, 1: string, 2: int, 3: int, 4: string}
 */
function pnd_sort_key(array $violation): array
{
    $occurrence = $violation['occurrence'] ?? null;

    return [
        isset($violation['entry']) && $occurrence === null ? 0 : 1,
        $occurrence['file'] ?? '',
        $occurrence['line'] ?? 0,
        $violation['entry'] ?? -1,
        $violation['kind'] . $violation['message'],
    ];
}

/**
 * Human-readable diagnostics. Every occurrence is named by file:line (for
 * navigation only), symbol and literal; an unclassified one also gets the
 * manifest change that would classify it, or the reason none can.
 *
 * @param list<array<string, mixed>> $violations
 */
function pnd_format_violations(array $violations, string $manifestLabel): string
{
    $lines = [];
    foreach ($violations as $violation) {
        $occurrence = $violation['occurrence'] ?? null;
        $where = $occurrence !== null
            ? sprintf('%s:%d %s %s', $occurrence['file'], $occurrence['line'], $occurrence['symbol'], json_encode($occurrence['literal'], PND_JSON))
            : $manifestLabel . (isset($violation['entry']) ? " classifications[{$violation['entry']}]" : '');
        $lines[] = sprintf('  [%s] %s', $violation['kind'], $where);
        if ($occurrence !== null && isset($violation['entry'])) {
            $lines[] = "      {$manifestLabel} classifications[{$violation['entry']}]";
        }
        $lines[] = '      ' . $violation['message'];
        if (array_key_exists('suggestion', $violation)) {
            array_push($lines, ...pnd_format_suggestion($violation['suggestion'], $manifestLabel));
        }
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param array<string, mixed> $suggestion
 *
 * @return list<string>
 */
function pnd_format_suggestion(array $suggestion, string $manifestLabel): array
{
    if (isset($suggestion['see'])) {
        return ["      The suggestion at line {$suggestion['see']} covers this occurrence too."];
    }
    if (isset($suggestion['unencodable'])) {
        return ['      Its literal is not valid UTF-8, which the JSON manifest cannot hold, so no entry can classify it: '
            . 'make the literal valid UTF-8, or ' . lcfirst(pnd_host_derived_hint())];
    }
    if (isset($suggestion['conflict'])) {
        return [sprintf(
            '      Its shape fits %s, but %s classifications[%d] classifies this literal in this symbol as %s, and one '
                . 'classification covers every such occurrence: give this one another literal or symbol, or the same shape.',
            $suggestion['fits'],
            $manifestLabel,
            $suggestion['conflict'],
            $suggestion['purpose'],
        )];
    }
    if (isset($suggestion['add'])) {
        $add = $suggestion['add'];

        return [
            sprintf(
                '      Its shape fits %s. If that is what it is, classify it in %s%s:',
                $add['purpose'],
                $manifestLabel,
                $add['occurrences'] > 1 ? " (one entry covers all {$add['occurrences']} occurrences in this symbol)" : '',
            ),
            '      ' . json_encode([...$add, 'rationale' => "<why this is {$add['purpose']}>"], PND_JSON),
        ];
    }
    if (isset($suggestion['raise'])) {
        return [sprintf(
            '      If it is the same kind of use, raise %s classifications[%d].occurrences from %d to %d.',
            $manifestLabel,
            $suggestion['raise'],
            $suggestion['from'],
            $suggestion['to'],
        )];
    }
    if (isset($suggestion['misfit'])) {
        return ["      It does not fit that classification's purpose, {$suggestion['misfit']}: {$suggestion['reason']}. " . pnd_host_derived_hint()];
    }

    return [$suggestion['host']
        ? '      Its occurrences in this symbol do not share one shape, and one classification covers them all: give them one shape, or '
            . lcfirst(pnd_host_derived_hint())
        : '      No purpose fits its shape. ' . pnd_host_derived_hint()];
}

/**
 * Scan $root against the manifest at $manifestPath. Any failure to scan is a
 * harness error (exit 2), never an uncaught exception.
 *
 * @return array{exit: int, stdout: string, stderr: string}
 */
function pnd_run(string $root, string $manifestPath): array
{
    try {
        return pnd_scan($root, $manifestPath);
    } catch (Throwable $exception) {
        return [
            'exit' => PND_EXIT_HARNESS,
            'stdout' => '',
            'stderr' => sprintf("Portable null-device gate: %s: %s\n", $exception::class, $exception->getMessage()),
        ];
    }
}

/**
 * @return array{exit: int, stdout: string, stderr: string}
 */
function pnd_scan(string $root, string $manifestPath): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $scan = pnd_governed_sources($root);

    $label = str_replace('\\', '/', $manifestPath);
    if (str_starts_with($label, $root . '/')) {
        $label = substr($label, strlen($root) + 1);
    }
    $raw = @file_get_contents($manifestPath);
    $manifest = null;
    $unreadable = null;
    if (!is_string($raw)) {
        $unreadable = "cannot read the classification manifest {$label}";
    } else {
        try {
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $unreadable = "the classification manifest {$label} is not JSON: {$exception->getMessage()}";
        }
    }

    $result = pnd_analyze($scan['files'], $scan['sources'], $manifest);
    $violations = $result['violations'];
    if ($unreadable !== null) {
        // Without a manifest nothing can be classified, so only the findings
        // that no classification could change are listed with the error.
        $violations = array_values(array_filter($violations, static fn(array $violation): bool => $violation['kind'] === 'direct-descriptor'));
        array_unshift($violations, ['kind' => 'malformed', 'message' => $unreadable]);
    }

    if ($violations !== []) {
        return [
            'exit' => PND_EXIT_VIOLATION,
            'stdout' => '',
            'stderr' => sprintf("Portable null-device gate: %d violation(s) in %d governed PHP files.\n", count($violations), $result['files'])
                . pnd_format_violations($violations, $label)
                . sprintf("Every %s literal must be host-derived or classified; see docs/specs/native-host-support.md.\n", PND_NULL_DEVICE),
        ];
    }

    $counts = [];
    foreach ($result['classified'] as $purpose => $count) {
        $counts[] = "{$count} {$purpose}";
    }

    return [
        'exit' => PND_EXIT_PASS,
        'stdout' => sprintf(
            "OK — %d governed PHP files; %d classified %s literals (%s); no hard-coded null-device descriptor.\n",
            $result['files'],
            array_sum($result['classified']),
            PND_NULL_DEVICE,
            implode(', ', $counts),
        ),
        'stderr' => '',
    ];
}
