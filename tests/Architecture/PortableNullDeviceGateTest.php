<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The portable null-device gate (FW-2678-PORTABLE-NULL-DEVICE-03, #2678):
 * bin/check-portable-null-device and its classification manifest,
 * tools/portable-null-device-classifications.json.
 *
 * Most cases are mutants of the real repository: the tracked governed
 * sources and the tracked manifest, read once, then changed in memory the way
 * a regression would change them, and analysed with the gate's own library.
 * Nothing is written to the checkout. The entrypoint case runs the real gate
 * as a child process. Every case runs unchanged under native Windows and
 * Linux PHP; the native-host contract executes this class on both hosts.
 */
#[CoversNothing]
final class PortableNullDeviceGateTest extends TestCase
{
    private const GATE = 'bin/check-portable-null-device';

    private const DIFFER = 'packages/config/src/Sync/ConfigDiffer.php';

    private const QUALIFIER = 'bin/qualify-candidate';

    private const SYNTHETIC = 'packages/demo/src/Demo.php';

    private static string $root;

    /** @var array{files: list<string>, sources: array<string, string>} */
    private static array $scan;

    /** @var array<string, mixed> */
    private static array $manifest;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/bin/lib/portable-null-device.php';
        self::$scan = \pnd_governed_sources(self::$root);
        self::$manifest = json_decode(
            (string) file_get_contents(self::$root . '/' . \PND_MANIFEST),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
    }

    #[Test]
    public function the_tracked_tree_passes_with_every_literal_classified(): void
    {
        $result = \pnd_analyze(self::$scan['files'], self::$scan['sources'], self::$manifest);

        self::assertSame([], $result['violations'], \pnd_format_violations($result['violations'], \PND_MANIFEST));
        self::assertSame(
            array_sum(array_column(self::$manifest['classifications'], 'occurrences')),
            array_sum($result['classified']),
            'Every classified occurrence must be found, and fit its purpose.',
        );
        foreach (\PND_PURPOSES as $purpose) {
            self::assertGreaterThan(0, $result['classified'][$purpose], "The tracked tree exercises {$purpose}.");
        }
    }

    #[Test]
    public function a_direct_hard_coded_descriptor_is_rejected_even_when_classified(): void
    {
        // The base shape of qcRunGit(), before this change.
        $mutant = self::mutate(
            self::source(self::QUALIFIER),
            "\$descriptors = [0 => ['null'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];\n    \$process = @proc_open(array_merge(['git', '-C', \$repo]",
            "\$descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];\n    \$process = @proc_open(array_merge(['git', '-C', \$repo]",
        );

        $violations = self::analyze([self::QUALIFIER => $mutant]);
        self::assertSame(['direct-descriptor'], self::kinds($violations));
        self::assertSame(self::QUALIFIER, $violations[0]['occurrence']['file']);
        self::assertSame('qcRunGit', $violations[0]['occurrence']['symbol']);
        self::assertStringContainsString("['null']", $violations[0]['message']);

        foreach (\PND_PURPOSES as $purpose) {
            $manifest = self::withClassification(self::$manifest, [
                'file' => self::QUALIFIER,
                'symbol' => 'qcRunGit',
                'literal' => '/dev/null',
                'occurrences' => 1,
                'purpose' => $purpose,
                'rationale' => 'An attempt to classify a descriptor.',
            ]);
            $violations = self::analyze([self::QUALIFIER => $mutant], $manifest);
            self::assertSame(['direct-descriptor', 'stale'], self::kinds($violations), $purpose);
            self::assertStringContainsString('cannot be classified', self::byKind($violations, 'stale')[0]['message']);
        }
    }

    #[Test]
    public function every_descriptor_spelling_is_recognised_and_host_derived_descriptors_are_not(): void
    {
        $descriptors = [
            "[0 => ['file', '/dev/null', 'r']]",
            '[0 => ["file", "/dev/null", "w"]]',
            "array(0 => array('file', '/dev/null', 'r'))",
            "[0 => [0 => 'file', 1 => '/dev/null', 2 => 'r']]",
            "[0 => ['file', '/dev/null']]",
        ];
        foreach ($descriptors as $spec) {
            $occurrences = \pnd_occurrences(self::SYNTHETIC, "<?php\nproc_open(\$command, {$spec}, \$pipes);\n");
            self::assertCount(1, $occurrences, $spec);
            self::assertTrue($occurrences[0]['descriptor'], $spec);

            // The same descriptor spelled inside a string: PHP code for
            // `php -r`, or code a generator writes, runs the same way.
            $code = "proc_open(\$command, {$spec}, \$pipes);";
            foreach (["<?php\n\$probe = <<<'PHP'\n{$code}\nPHP;\n", "<?php\n\$probe = " . var_export($code, true) . ";\n"] as $source) {
                $embedded = \pnd_occurrences(self::SYNTHETIC, $source);
                self::assertCount(1, $embedded, $source);
                self::assertTrue($embedded[0]['descriptor'], $source);
            }
        }

        $hostDerived = "<?php\nproc_open(\$command, [0 => ['null'], 1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], \$pipes);\n";
        $occurrences = \pnd_occurrences(self::SYNTHETIC, $hostDerived);
        self::assertCount(1, $occurrences);
        self::assertFalse($occurrences[0]['descriptor']);
        self::assertSame('platform-derived', \pnd_fitting_purpose($occurrences[0]));

        // A whole descriptor chosen by host still hard-codes the POSIX path in
        // a descriptor; ['null'] is the one accepted form, and the diagnostic
        // says so without claiming this code fails.
        $choice = "<?php\nproc_open(\$command, [0 => PHP_OS_FAMILY === 'Windows' ? ['file', 'NUL', 'r'] : ['file', '/dev/null', 'r']], \$pipes);\n";
        $violations = \pnd_analyze([self::SYNTHETIC], [self::SYNTHETIC => $choice], self::withClassifications([]))['violations'];
        self::assertSame(['direct-descriptor'], self::kinds($violations));
        self::assertStringContainsString('not even inside a host choice', $violations[0]['message']);
        self::assertStringContainsString("Use the ['null'] descriptor", $violations[0]['message']);

        $notFile = "<?php\n\$labels = ['pipe', '/dev/null'];\n";
        self::assertFalse(\pnd_occurrences(self::SYNTHETIC, $notFile)[0]['descriptor']);
    }

    #[Test]
    public function a_new_unclassified_literal_is_rejected_with_actionable_diagnostics(): void
    {
        $source = self::source(self::DIFFER);

        // A null device opened in portable package code: no purpose fits.
        $opened = self::mutate($source, "final class ConfigDiffer\n{\n", "final class ConfigDiffer\n{\n    private function sink(): mixed\n    {\n        return fopen('/dev/null', 'w');\n    }\n\n");
        $violations = self::analyze([self::DIFFER => $opened]);
        self::assertSame(['unclassified'], self::kinds($violations));
        self::assertSame('ConfigDiffer::sink', $violations[0]['occurrence']['symbol']);
        $report = \pnd_format_violations($violations, \PND_MANIFEST);
        self::assertStringContainsString(self::DIFFER . ':' . $violations[0]['occurrence']['line'] . ' ConfigDiffer::sink "/dev/null"', $report);
        self::assertStringContainsString('No purpose fits its shape', $report);
        self::assertStringContainsString("['null']", $report);

        // A host-shell redirection: the report names the fitting purpose and
        // the exact classification it would need.
        $shell = self::mutate($source, "final class ConfigDiffer\n{\n", "final class ConfigDiffer\n{\n    private function probe(): void\n    {\n        exec('git status 2>/dev/null');\n    }\n\n");
        $violations = self::analyze([self::DIFFER => $shell]);
        self::assertSame(['unclassified'], self::kinds($violations));
        $report = \pnd_format_violations($violations, \PND_MANIFEST);
        self::assertStringContainsString('Its shape fits posix-only-shell', $report);
        self::assertStringContainsString('{"file":"' . self::DIFFER . '","symbol":"ConfigDiffer::probe","literal":"git status 2>/dev/null","occurrences":1,"purpose":"posix-only-shell"', $report);

        // One more occurrence of an already classified literal in the same symbol.
        $again = self::mutate(
            $source,
            "diff: \$this->unifiedDiff('', \$syncYaml, '/dev/null', \"b/{\$ref}\"),",
            "diff: \$this->unifiedDiff('', \$syncYaml, '/dev/null', \"b/{\$ref}\") . \$this->unifiedDiff('', \$syncYaml, '/dev/null', \"b/{\$ref}\"),",
        );
        $violations = self::analyze([self::DIFFER => $again]);
        self::assertSame(['unclassified'], self::kinds($violations));
        self::assertSame('ConfigDiffer::buildSyncOnlyResult', $violations[0]['occurrence']['symbol']);
        self::assertStringContainsString('2 occurrences of this literal in this symbol, 1 classified', $violations[0]['message']);
    }

    #[Test]
    public function every_suggestion_classifies_exactly_what_it_reports(): void
    {
        $source = self::source(self::DIFFER);
        $index = self::entryIndex(self::DIFFER, 'ConfigDiffer::buildSyncOnlyResult', '/dev/null');

        // Two identical new redirections in one symbol: one entry for both,
        // and the second occurrence points back at it.
        $twice = self::mutate($source, "final class ConfigDiffer\n{\n", "final class ConfigDiffer\n{\n    private function probe(): void\n    {\n        exec('git status 2>/dev/null');\n        exec('git status 2>/dev/null');\n    }\n\n");
        $violations = self::analyze([self::DIFFER => $twice]);
        self::assertSame(['unclassified', 'unclassified'], self::kinds($violations));
        $first = $violations[0]['occurrence']['line'];
        self::assertSame(['see' => $first], $violations[1]['suggestion']);
        $report = \pnd_format_violations($violations, \PND_MANIFEST);
        self::assertStringContainsString('one entry covers all 2 occurrences in this symbol', $report);
        self::assertStringContainsString("The suggestion at line {$first} covers this occurrence too.", $report);
        self::assertSame([], self::analyze([self::DIFFER => $twice], self::applySuggestions(self::$manifest, $violations)), 'The pasted suggestion classifies both.');

        // One more occurrence of a classified literal: raise the count; a
        // second entry for the same literal would be a duplicate.
        $again = self::mutate(
            $source,
            "diff: \$this->unifiedDiff('', \$syncYaml, '/dev/null', \"b/{\$ref}\"),",
            "diff: \$this->unifiedDiff('', \$syncYaml, '/dev/null', \"b/{\$ref}\") . \$this->unifiedDiff('', \$syncYaml, '/dev/null', \"b/{\$ref}\"),",
        );
        $violations = self::analyze([self::DIFFER => $again]);
        self::assertSame(['raise' => $index, 'from' => 1, 'to' => 2], $violations[0]['suggestion']);
        self::assertStringContainsString("raise tools/portable-null-device-classifications.json classifications[{$index}].occurrences from 1 to 2", \pnd_format_violations($violations, \PND_MANIFEST));
        self::assertSame([], self::analyze([self::DIFFER => $again], self::applySuggestions(self::$manifest, $violations)), 'The raised count classifies both.');

        // An extra occurrence that does not fit the classification's purpose
        // cannot be covered by raising its count.
        $sink = "        \$sink = fopen('/dev/null', 'w');\n";
        $returnSyncOnly = "        return new DiffResult(\n            ref: \$ref,\n            status: DiffResult::STATUS_SYNC_ONLY,";
        $misfit = self::mutate($source, $returnSyncOnly, $sink . $returnSyncOnly);
        $violations = self::analyze([self::DIFFER => $misfit]);
        self::assertSame(['unclassified'], self::kinds($violations));
        self::assertSame('semantic-diff-marker', $violations[0]['suggestion']['misfit']);
        self::assertStringContainsString("It does not fit that classification's purpose, semantic-diff-marker", \pnd_format_violations($violations, \PND_MANIFEST));

        // A surplus that mixes both: the raise sits on the label that fits and
        // counts only fitting labels, and the misfit gets its own remedy, even
        // though it comes first in the file.
        $mixedSurplus = self::mutate($misfit, "diff: \$this->unifiedDiff('', \$syncYaml, '/dev/null', \"b/{\$ref}\"),", "diff: \$this->unifiedDiff('', \$syncYaml, '/dev/null', \"b/{\$ref}\") . \$this->unifiedDiff('', \$syncYaml, '/dev/null', \"b/{\$ref}\"),");
        $violations = self::analyze([self::DIFFER => $mixedSurplus]);
        self::assertSame(['unclassified', 'unclassified'], self::kinds($violations));
        self::assertSame('semantic-diff-marker', $violations[0]['suggestion']['misfit'], 'The fopen() line, first in the file, keeps its own remedy.');
        self::assertSame(['raise' => $index, 'from' => 1, 'to' => 2], $violations[1]['suggestion']);
        $repaired = str_replace($sink, "        \$sink = fopen('php://memory', 'w');\n", $mixedSurplus);
        self::assertSame([], self::analyze([self::DIFFER => $repaired], self::applySuggestions(self::$manifest, $violations)), 'Removing the misfit and raising the count classifies the rest.');

        // A surplus of another purpose conflicts with the one classification
        // its file, symbol and literal can have; host-deriving it again would
        // not help.
        $audit = 'tools/audit/GenerateLayerAudit.php';
        $probe = "    \$hasRg = trim((string) shell_exec('command -v rg 2>/dev/null || true'));\n";
        $conflict = self::mutate(self::source($audit), $probe, "    \$probe = PHP_OS_FAMILY === 'Windows' ? 'where rg 2>NUL' : 'command -v rg 2>/dev/null || true';\n" . $probe);
        $violations = self::analyze([$audit => $conflict]);
        self::assertSame(['unclassified'], self::kinds($violations));
        self::assertSame('platform-derived', $violations[0]['suggestion']['fits']);
        self::assertStringContainsString('Its shape fits platform-derived, but tools/portable-null-device-classifications.json classifications[', \pnd_format_violations($violations, \PND_MANIFEST));
        self::assertStringNotContainsString('Take the device from the host', \pnd_format_violations($violations, \PND_MANIFEST));

        // Occurrences of one literal in one symbol that do not share a shape.
        $mixed = "<?php\nfunction probe(): void\n{\n    exec('tool 2>/dev/null');\n    \$log = 'Windows hosts use NUL: ' . 'tool 2>/dev/null';\n}\n";
        $violations = \pnd_analyze([self::SYNTHETIC], [self::SYNTHETIC => $mixed], self::withClassifications([]))['violations'];
        self::assertSame(['host' => true], $violations[0]['suggestion']);
        self::assertStringContainsString('do not share one shape', \pnd_format_violations($violations, \PND_MANIFEST));
    }

    #[Test]
    public function deleting_a_classified_occurrence_makes_its_classification_stale(): void
    {
        $removed = self::mutate(
            self::source(self::DIFFER),
            "\$this->unifiedDiff('', \$syncYaml, '/dev/null', \"b/{\$ref}\")",
            "\$this->unifiedDiff('', \$syncYaml, 'a/' . \$ref, \"b/{\$ref}\")",
        );
        $violations = self::analyze([self::DIFFER => $removed]);
        self::assertSame(['stale'], self::kinds($violations));
        self::assertSame(self::entryIndex(self::DIFFER, 'ConfigDiffer::buildSyncOnlyResult', '/dev/null'), $violations[0]['entry']);
        self::assertStringContainsString('no longer occurs', $violations[0]['message']);

        $fewer = self::mutate(
            self::source('bin/check-skeleton-docker-secret-exclusion'),
            "        'Solaris' => '/dev/null',\n",
            '',
        );
        $violations = self::analyze(['bin/check-skeleton-docker-secret-exclusion' => $fewer]);
        self::assertSame(['stale'], self::kinds($violations));
        self::assertStringContainsString('declares 6 occurrence(s)', $violations[0]['message']);
        self::assertStringContainsString('found 5', $violations[0]['message']);
    }

    #[Test]
    public function an_altered_purpose_or_file_is_rejected(): void
    {
        // Every relabelling of every tracked classification is rejected: the
        // three purposes are mutually exclusive shapes.
        foreach (self::$manifest['classifications'] as $index => $entry) {
            foreach (\PND_PURPOSES as $purpose) {
                if ($purpose === $entry['purpose']) {
                    continue;
                }
                $manifest = self::$manifest;
                $manifest['classifications'][$index]['purpose'] = $purpose;
                $kinds = array_values(array_unique(self::kinds(self::analyze([], $manifest))));
                self::assertSame(['purpose-mismatch'], $kinds, "{$entry['file']} {$entry['symbol']} relabelled {$purpose}");
            }
        }

        // Moved to another governed file: the entry is stale and the real
        // occurrence is unclassified.
        $manifest = self::$manifest;
        $index = self::entryIndex(self::DIFFER, 'ConfigDiffer::buildSyncOnlyResult', '/dev/null');
        $manifest['classifications'][$index]['file'] = 'packages/config/src/Sync/ConfigImporter.php';
        $manifest['classifications'] = self::sorted($manifest['classifications']);
        $violations = self::analyze([], $manifest);
        self::assertSame(['stale', 'unclassified'], self::kinds($violations));
        self::assertSame(self::DIFFER, self::byKind($violations, 'unclassified')[0]['occurrence']['file']);

        // Moved to a file that is not a governed PHP file in this checkout.
        $manifest = self::$manifest;
        $manifest['classifications'][$index]['file'] = 'packages/config/src/Sync/ConfigDifferRenamed.php';
        $manifest['classifications'] = self::sorted($manifest['classifications']);
        self::assertSame(['stale', 'unclassified'], self::kinds(self::analyze([], $manifest)));
    }

    #[Test]
    public function a_duplicate_classification_is_rejected(): void
    {
        $classifications = self::$manifest['classifications'];
        $index = self::entryIndex(self::DIFFER, 'ConfigDiffer::buildSyncOnlyResult', '/dev/null');

        $adjacent = $classifications;
        array_splice($adjacent, $index + 1, 0, [$classifications[$index]]);
        self::assertSame(['duplicate'], self::kinds(self::analyze([], self::withClassifications($adjacent))));

        $otherPurpose = $classifications;
        $copy = $classifications[$index];
        $copy['purpose'] = 'posix-only-shell';
        array_splice($otherPurpose, $index + 1, 0, [$copy]);
        self::assertSame(['duplicate'], self::kinds(self::analyze([], self::withClassifications($otherPurpose))));

        $apart = [...$classifications, $classifications[$index]];
        self::assertSame(['duplicate'], self::kinds(self::analyze([], self::withClassifications($apart))));
    }

    #[Test]
    public function malformed_or_overly_broad_classifications_are_rejected(): void
    {
        // Each case must be rejected for its own reason, not for a side
        // effect such as the sort order its replacement file name breaks.
        $index = self::entryIndex(self::DIFFER, 'ConfigDiffer::buildSyncOnlyResult', '/dev/null');
        $entry = static fn(array $changes): array => array_replace(self::$manifest['classifications'][$index], $changes);
        $broad = [
            'a glob' => [['file' => 'packages/config/src/Sync/*.php'], 'is a pattern or a directory'],
            'a directory' => [['file' => 'packages/config/src/Sync'], 'is a pattern or a directory'],
            'a trailing slash' => [['file' => 'packages/config/src/Sync/'], 'is a pattern or a directory'],
            'a symbol pattern' => [['symbol' => 'ConfigDiffer::*'], 'is a pattern; name exactly one symbol'],
        ];
        foreach ($broad as $case => [$changes, $reason]) {
            $violations = self::analyze([], self::withEntryAt($index, $entry($changes)));
            self::assertRejectedFor($violations, 'overly-broad', $reason, $case);
            self::assertNotContains('purpose-mismatch', self::kinds($violations), $case);
        }

        $relative = 'must be a repository-relative path with / separators';
        $outside = 'is outside the governed surface';
        $rationale = 'rationale must be one non-empty line';
        $malformed = [
            'an absolute path' => [['file' => '/packages/config/src/Sync/ConfigDiffer.php'], $relative],
            'a drive path' => [['file' => 'C:/packages/config/src/Sync/ConfigDiffer.php'], $relative],
            'backslashes' => [['file' => 'packages\\config\\src\\Sync\\ConfigDiffer.php'], $relative],
            'a dot segment' => [['file' => 'packages/config/../config/src/Sync/ConfigDiffer.php'], $relative],
            'a test file' => [['file' => 'tests/Architecture/PortableNullDeviceGateTest.php'], $outside],
            'a vendor file' => [['file' => 'vendor/acme/lib/src/Lib.php'], $outside],
            'an empty symbol' => [['symbol' => ''], 'symbol must be a non-empty string'],
            'a malformed symbol' => [['symbol' => 'ConfigDiffer->buildSyncOnlyResult'], 'must be {main}, a function, a class, or Class::method'],
            'a literal without the device' => [['literal' => 'b/'], 'literal must be the content of a string token'],
            'zero occurrences' => [['occurrences' => 0], 'occurrences must be a positive integer'],
            'a string count' => [['occurrences' => '1'], 'occurrences must be a positive integer'],
            'an unknown purpose' => [['purpose' => 'legacy'], 'purpose must be one of'],
            'an empty rationale' => [['rationale' => '  '], $rationale],
            'a multi-line rationale' => [['rationale' => "line one\nline two"], $rationale],
            'an overlong rationale' => [['rationale' => str_repeat('x', 501)], $rationale],
        ];
        foreach ($malformed as $case => [$changes, $reason]) {
            self::assertRejectedFor(self::analyze([], self::withEntryAt($index, $entry($changes))), 'malformed', $reason, $case);
        }

        $keys = 'a classification must have exactly the keys';
        $schema = 'schema must be ';
        $object = 'the manifest must be a JSON object';
        $shapes = [
            'an unknown entry key' => [self::withEntryAt($index, [...$entry([]), 'line' => 210]), $keys],
            'a missing entry key' => [self::withEntryAt($index, array_diff_key($entry([]), ['rationale' => true])), $keys],
            'a list entry' => [self::withEntryAt($index, ['file', 'symbol']), 'a classification must be an object'],
            'unsorted entries' => [self::withClassifications(array_reverse(self::$manifest['classifications'])), 'must be sorted by file, then symbol, then literal'],
            'another schema' => [[...self::$manifest, 'schema' => 'waaseyaa.other'], $schema],
            'another schema version' => [[...self::$manifest, 'schema_version' => 2], $schema],
            'an unknown manifest key' => [[...self::$manifest, 'exemptions' => []], 'the manifest must have exactly the keys'],
            'a missing statement' => [array_diff_key(self::$manifest, ['statement' => true]), 'statement must be a non-empty string'],
            'a purpose vocabulary that drifted' => [[...self::$manifest, 'purposes' => ['platform-derived' => 'x', 'legacy' => 'y']], 'purposes must define exactly'],
            'classifications that are not a list' => [[...self::$manifest, 'classifications' => ['a' => $entry([])]], 'classifications must be a list'],
            'a list for a manifest' => [[self::$manifest], $object],
            'a scalar for a manifest' => ['classifications', $object],
        ];
        foreach ($shapes as $case => [$manifest, $reason]) {
            self::assertRejectedFor(self::analyze([], $manifest), 'malformed', $reason, $case);
        }
    }

    #[Test]
    public function the_platform_derived_implementations_are_accepted(): void
    {
        $tracked = [
            ['bin/check-skeleton-docker-secret-exclusion', 'nullDevice'],
            ['bin/check-skeleton-docker-secret-exclusion', 'selfTest'],
            ['bin/lib/vendor-freshness.php', 'vendor_freshness_static_class_data'],
            ['packages/cli/src/ProjectInit/ProcOpenProjectInitProcessRunner.php', 'ProcOpenProjectInitProcessRunner::nullInputDevice'],
            ['packages/frankenphp/src/Binary/BinaryResolver.php', 'BinaryResolver::lookupOnPath'],
        ];
        foreach ($tracked as [$file, $symbol]) {
            $occurrences = array_filter(
                \pnd_occurrences($file, self::$scan['sources'][$file]),
                static fn(array $occurrence): bool => $occurrence['symbol'] === $symbol,
            );
            self::assertNotSame([], $occurrences, "{$file} {$symbol}");
            foreach ($occurrences as $occurrence) {
                self::assertSame('platform-derived', \pnd_fitting_purpose($occurrence), "{$file} {$symbol}");
            }
        }

        $forms = [
            "return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';",
            "return (\$family ?? \\PHP_OS_FAMILY) === 'Windows' ? 'NUL' : '/dev/null';",
            "return DIRECTORY_SEPARATOR === '\\\\' ? 'nul' : '/dev/null';",
            "\$command = \$isWindows ? 'where tool 2>NUL' : 'command -v tool 2>/dev/null';",
        ];
        foreach ($forms as $form) {
            $source = "<?php\nfunction device(?string \$family = null, bool \$isWindows = false): string\n{\n    {$form}\n}\n";
            $occurrence = \pnd_occurrences(self::SYNTHETIC, $source)[0];
            self::assertSame('platform-derived', \pnd_fitting_purpose($occurrence), $form);
            $manifest = self::withClassifications([[
                'file' => self::SYNTHETIC,
                'symbol' => 'device',
                'literal' => $occurrence['literal'],
                'occurrences' => 1,
                'purpose' => 'platform-derived',
                'rationale' => 'A host choice.',
            ]]);
            self::assertSame([], \pnd_analyze([self::SYNTHETIC], [self::SYNTHETIC => $source], $manifest)['violations'], $form);
        }

        // Naming both devices without a host signal is not a host choice.
        $picked = \pnd_occurrences(self::SYNTHETIC, "<?php\n\$device = ['NUL', '/dev/null'][1];\n")[0];
        self::assertNull(\pnd_fitting_purpose($picked));
    }

    #[Test]
    public function the_semantic_diff_markers_are_accepted(): void
    {
        $tracked = [
            [self::DIFFER, 'ConfigDiffer::buildSyncOnlyResult'],
            [self::DIFFER, 'ConfigDiffer::buildActiveOnlyResult'],
            ['packages/bimaaji/src/Patch/PatchGenerator.php', 'PatchGenerator::generateAddField'],
        ];
        foreach ($tracked as [$file, $symbol]) {
            $occurrences = array_values(array_filter(
                \pnd_occurrences($file, self::$scan['sources'][$file]),
                static fn(array $occurrence): bool => $occurrence['symbol'] === $symbol,
            ));
            self::assertCount(1, $occurrences, "{$file} {$symbol}");
            self::assertSame('semantic-diff-marker', \pnd_fitting_purpose($occurrences[0]), "{$file} {$symbol}");
        }

        $forms = [
            'return "diff --git a/{$path} b/{$path}\\ndeleted file\\n--- a/{$path}\\n+++ /dev/null\\n";',
            "return \$this->header('/dev/null', 'b/' . \$path);",
            // A patch stays diff data even when a line it adds redirects.
            'return "--- /dev/null\\n+++ b/bin/setup\\n@@ -0,0 +1 @@\\n+command -v git >/dev/null || exit 1\\n";',
        ];
        foreach ($forms as $form) {
            $occurrence = \pnd_occurrences(self::SYNTHETIC, "<?php\nfunction header(string \$path): string\n{\n    {$form}\n}\n")[0];
            self::assertSame('semantic-diff-marker', \pnd_fitting_purpose($occurrence), $form);
        }

        // A bare path with no diff label beside it is not diff data.
        $bare = \pnd_occurrences(self::SYNTHETIC, "<?php\n\$sink = '/dev/null';\n")[0];
        self::assertNull(\pnd_fitting_purpose($bare));
    }

    #[Test]
    public function posix_only_shell_fragments_are_accepted_as_command_text_without_a_nul_counterpart(): void
    {
        $recipe = 'packages/deployer/recipe/waaseyaa.php';
        $occurrences = \pnd_occurrences($recipe, self::$scan['sources'][$recipe]);
        self::assertCount(2, $occurrences);
        foreach ($occurrences as $occurrence) {
            self::assertSame('{main}', $occurrence['symbol'], 'A closure belongs to the symbol that encloses it.');
            self::assertSame('posix-only-shell', \pnd_fitting_purpose($occurrence));
        }

        $fragments = [
            // Redirections, spaced or not, quoted or not.
            'cmd >/dev/null 2>&1', 'cmd &> /dev/null', 'cmd >> /dev/null', 'cmd < /dev/null', 'cmd </dev/null', 'tool >"/dev/null" 2>&1',
            // The device as a word of the command, which a remote POSIX host
            // (Deployer's run()) resolves, never the local one.
            'curl -s -o /dev/null https://example.test', 'GIT_CONFIG_GLOBAL=/dev/null git status', 'git diff --no-index /dev/null b.txt',
            "curl -s -o '/dev/null' https://example.test",
        ];
        foreach ($fragments as $fragment) {
            // var_export() writes a valid single-quoted literal, escaping any quote.
            $occurrence = \pnd_occurrences(self::SYNTHETIC, "<?php\nrun(" . var_export($fragment, true) . ");\n")[0];
            self::assertSame('posix-only-shell', \pnd_fitting_purpose($occurrence), $fragment);
        }
        $doubleQuoted = \pnd_occurrences(self::SYNTHETIC, "<?php\nrun(\"curl -s -o '/dev/null' \$url\");\n")[0];
        self::assertSame('posix-only-shell', \pnd_fitting_purpose($doubleQuoted));

        $notCommandText = [
            'a host pair' => "exec(\$windows ? 'cmd 2>NUL' : 'cmd 2>/dev/null');",
            'a bare argument' => "proc_open(['curl', '-o', '/dev/null', \$url], \$descriptors, \$pipes);",
            'an argument word' => "proc_open(['git', '-c', 'core.hooksPath=/dev/null', 'status'], \$descriptors, \$pipes);",
            'an environment value' => "putenv('GIT_CONFIG_GLOBAL=/dev/null');",
            'another path' => "\$path = 'cmd > /dev/nullable';",
            // PHP or JSON text inside a string is not command text: a quoted
            // path there does not end a shell word, and `=>` is no redirection.
            'an embedded PHP list' => "\$code = <<<'PHP'\n\$devices = ['stdin', '/dev/null'];\nPHP;",
            'an embedded PHP map' => "\$code = <<<'PHP'\n\$devices = ['stdin' => '/dev/null'];\nPHP;",
            'embedded JSON' => "\$json = '{\"stdin\": \"/dev/null\"}';",
        ];
        foreach ($notCommandText as $case => $statement) {
            $occurrence = \pnd_occurrences(self::SYNTHETIC, "<?php\n{$statement}\n")[0];
            self::assertNotNull(\pnd_purpose_misfit('posix-only-shell', $occurrence), $case);
        }
    }

    #[Test]
    public function a_statement_ends_at_its_semicolon_and_block_braces(): void
    {
        // A host signal or NUL in a neighbouring statement never leaks into
        // the occurrence's shape, whichever boundary separates them.
        $fitsNothing = [
            'after a semicolon' => "\$log = 'Windows hosts use NUL'; return fopen('/dev/null', 'w');",
            'across an if/else' => "if (PHP_OS_FAMILY === 'Windows') { \$device = 'NUL'; } else { \$device = '/dev/null'; } return \$device;",
        ];
        foreach ($fitsNothing as $case => $body) {
            $occurrences = \pnd_occurrences(self::SYNTHETIC, "<?php\nfunction probe()\n{\n    {$body}\n}\n");
            self::assertCount(1, $occurrences, $case);
            self::assertNull(\pnd_fitting_purpose($occurrences[0]), $case);
        }

        $stillPosix = [
            'after a semicolon' => "\$device = PHP_OS_FAMILY === 'Windows' ? 'NUL' : 'nothing'; exec('cmd 2>/dev/null');",
            'inside a block' => "if (PHP_OS_FAMILY === 'Windows' && \$device === 'NUL') { exec('cmd 2>/dev/null'); }",
            'after a closing brace' => "\$command = match (PHP_OS_FAMILY) { 'Windows' => 'NUL', default => 'none' } . ' ' . 'cmd 2>/dev/null';",
        ];
        foreach ($stillPosix as $case => $body) {
            $occurrences = \pnd_occurrences(self::SYNTHETIC, "<?php\nfunction probe()\n{\n    {$body}\n}\n");
            self::assertCount(1, $occurrences, $case);
            self::assertSame('posix-only-shell', \pnd_fitting_purpose($occurrences[0]), $case);
        }

        // Interpolation braces are not statement boundaries.
        $interpolated = \pnd_occurrences(self::SYNTHETIC, "<?php\n\$diff = \$this->unifiedDiff(\$old, '', \"a/{\$ref}\", '/dev/null');\n");
        self::assertSame('semantic-diff-marker', \pnd_fitting_purpose($interpolated[0]));
    }

    #[Test]
    public function symbols_follow_declarations_not_keyword_arguments(): void
    {
        $sources = [
            'function &byReference() { return \'/dev/null\'; }' => 'byReference',
            '$o = new class { public function m() { return \'/dev/null\'; } };' => 'class@anonymous::m',
            '$o = new readonly class { public function m() { return \'/dev/null\'; } };' => 'class@anonymous::m',
            '$o = new #[Marker] class (1) extends Base { public function n() { return \'/dev/null\'; } };' => 'class@anonymous::n',
            'function f($c) { if ($c === Foo::class) { return \'/dev/null\'; } }' => 'f',
            'function g($x) { if ($x->has(class: 1)) { return \'/dev/null\'; } }' => 'g',
            'class C { public function m($x) { if ($x->call(function: 1)) { return \'/dev/null\'; } } }' => 'C::m',
            'enum Suit: string { case A = \'a\'; public function label(): string { return \'/dev/null\'; } }' => 'Suit::label',
            'interface I { const DEVICE = \'/dev/null\'; }' => 'I',
            'trait T { public function t() { return fn() => \'/dev/null\'; } }' => 'T::t',
            'class K { private string $device = \'/dev/null\'; }' => 'K',
            'class Html { public function class() { return \'/dev/null\'; } }' => 'Html::class',
            'class Html { public function function() { return \'/dev/null\'; } }' => 'Html::function',
            'class Html { public function interface() { return \'/dev/null\'; } }' => 'Html::interface',
            'class Html { public function trait() { return \'/dev/null\'; } }' => 'Html::trait',
            'task(\'clear\', function (): void { run(\'rm -f x 2>/dev/null\'); });' => '{main}',
        ];
        foreach ($sources as $source => $symbol) {
            $occurrences = \pnd_occurrences(self::SYNTHETIC, "<?php\n{$source}\n");
            self::assertCount(1, $occurrences, $source);
            self::assertSame($symbol, $occurrences[0]['symbol'], $source);
        }
    }

    #[Test]
    public function invalid_utf8_and_unreadable_repositories_stay_within_the_exit_codes(): void
    {
        // A literal that is not valid UTF-8 is reported, never a crash.
        $source = "<?php\nexec(\"tool \xFF 2>/dev/null\");\n";
        $violations = \pnd_analyze([self::SYNTHETIC], [self::SYNTHETIC => $source], self::withClassifications([]))['violations'];
        self::assertSame(['unclassified'], self::kinds($violations));
        $report = \pnd_format_violations($violations, \PND_MANIFEST);
        self::assertStringContainsString(self::SYNTHETIC . ":2 {main} \"tool \u{FFFD} 2>/dev/null\"", $report);
        // JSON cannot carry the raw byte, so no pasted entry could ever match
        // it: the gate says so instead of suggesting one.
        self::assertSame(['unencodable' => true], $violations[0]['suggestion']);
        self::assertStringContainsString('not valid UTF-8, which the JSON manifest cannot hold', $report);
        self::assertStringNotContainsString('"literal":', $report);

        // A root Git cannot enumerate is a harness error, exit 2.
        $result = \pnd_run(sys_get_temp_dir() . '/waaseyaa-null-device-no-repository-' . bin2hex(random_bytes(6)), self::$root . '/' . \PND_MANIFEST);
        self::assertSame(2, $result['exit'], $result['stderr']);
        self::assertStringStartsWith('Portable null-device gate: ', $result['stderr']);
    }

    #[Test]
    public function only_governed_production_php_is_scanned(): void
    {
        $paths = [
            'bin/check-portable-null-device' => true,
            'bin/lib/portable-null-device.php' => true,
            'packages/cli/src/ProjectInit/ProcOpenProjectInitProcessRunner.php' => true,
            'packages/deployer/recipe/waaseyaa.php' => true,
            'packages/testing/src/Anything.php' => true,
            'scripts/layer0_audit/dead_code_candidates.php' => true,
            'skeleton/bin/post-create-setup.php' => true,
            'tools/audit/GenerateLayerAudit.php' => true,
            'tests/Architecture/PortableNullDeviceGateTest.php' => false,
            'packages/config/tests/Unit/Sync/ConfigDifferTest.php' => false,
            'packages/entity/testing/EntityTestCase.php' => false,
            'packages/admin/e2e/fixture.php' => false,
            'skeleton/tests/Unit/Http/BootFailureResponderTest.php' => false,
            'benchmarks/BenchmarkProcessRunner.php' => false,
            'docs/public-surface-map.php' => false,
            'kitty-specs/archive/x/tool.php' => false,
            'vendor/acme/lib/src/Lib.php' => false,
            'packages/cli/vendor/acme/Lib.php' => false,
        ];
        foreach ($paths as $path => $governed) {
            self::assertSame($governed, \pnd_is_governed_path($path), $path);
        }

        $php = [
            'a PHP shebang' => "#!/usr/bin/env php\n<?php\n",
            'a CRLF PHP shebang' => "#!/usr/bin/env php\r\n<?php\r\n",
            'a versioned PHP shebang' => "#!/usr/bin/env php8.5\n<?php\n",
            'a blank line after the shebang' => "#!/usr/bin/php -d display_errors=1\n\n<?php\n",
            'an open tag' => "<?php\n",
            'an upper-case open tag' => "<?PHP\n",
            'a byte-order mark' => "\u{FEFF}<?php\n",
        ];
        foreach ($php as $case => $head) {
            self::assertTrue(\pnd_is_php_source('packages/cli/stubs/job.stub', $head), $case);
        }
        self::assertTrue(\pnd_is_php_source('packages/demo/src/Demo.php', ''));
        self::assertFalse(\pnd_is_php_source('bin/check-no-secrets', "#!/usr/bin/env bash\nset -euo pipefail\n"));
        self::assertFalse(\pnd_is_php_source('bin/tool', "#!/usr/bin/env bash\n# calls php later\nphp -v\n"));
        self::assertFalse(\pnd_is_php_source('bin/tool', "#!/usr/bin/env phpunit\n"));
        self::assertFalse(\pnd_is_php_source('tools/native-host-contract.json', "{\n"));

        self::assertContains(self::GATE, self::$scan['files']);
        self::assertContains(self::QUALIFIER, self::$scan['files']);
        self::assertSame([], array_values(array_filter(self::$scan['files'], static fn(string $path): bool => !\pnd_is_governed_path($path))));
    }

    #[Test]
    public function comments_are_documentation_and_every_string_token_is_inspected(): void
    {
        $source = <<<'PHP'
            <?php
            // Redirect to /dev/null.
            # Or 2>/dev/null.
            /* The null device, /dev/null. */
            /** @see /dev/null */
            $heredoc = <<<SH
            tool 2>/dev/null {$suffix}
            SH;
            $nowdoc = <<<'SH'
            --- /dev/null
            SH;
            ?>
            Inline text naming /dev/null.
            PHP;

        $occurrences = \pnd_occurrences(self::SYNTHETIC, $source);
        self::assertSame([7, 10, 13], array_column($occurrences, 'line'));
        self::assertSame(['posix-only-shell', 'semantic-diff-marker', null], array_map(\pnd_fitting_purpose(...), $occurrences));
    }

    #[Test]
    public function anchors_survive_unrelated_edits_and_line_endings(): void
    {
        $shifted = self::mutate(
            self::source(self::DIFFER),
            "final class ConfigDiffer\n{\n",
            "final class ConfigDiffer\n{\n" . str_repeat("\n", 40) . "    private function unrelated(): int\n    {\n        return 1;\n    }\n\n",
        );
        self::assertSame([], self::analyze([self::DIFFER => $shifted]));

        // A CRLF checkout must scan exactly as an LF one, including a literal
        // that spans lines, whose token would otherwise carry the carriage
        // returns. Most governed PHP is eol=lf, but core.autocrlf on the
        // hosted Windows runner converts the governed files without an eol
        // attribute (the CLI stubs and templates, for example).
        $crlf = array_map(static fn(string $source): string => str_replace("\n", "\r\n", $source), self::$scan['sources']);
        self::assertSame([], \pnd_analyze(self::$scan['files'], $crlf, self::$manifest)['violations']);
        foreach (self::$scan['sources'] as $path => $source) {
            self::assertSame(\pnd_occurrences($path, $source), \pnd_occurrences($path, $crlf[$path]), $path);
        }
        $multiLine = "<?php\n\$script = <<<SH\ncleanup 2>/dev/null\nexit 0\nSH;\n";
        $occurrences = \pnd_occurrences(self::SYNTHETIC, str_replace("\n", "\r\n", $multiLine));
        self::assertSame(\pnd_occurrences(self::SYNTHETIC, $multiLine), $occurrences);
        self::assertSame("cleanup 2>/dev/null\nexit 0\n", $occurrences[0]['literal']);
    }

    #[Test]
    public function the_gate_entrypoint_passes_the_tracked_tree_and_fails_closed(): void
    {
        [$exit, $stdout, $stderr] = self::runGate([]);
        self::assertSame(0, $exit, $stdout . $stderr);
        self::assertStringStartsWith('OK — ', $stdout);
        self::assertStringContainsString('no hard-coded null-device descriptor', $stdout);

        $manifest = self::$manifest;
        array_splice($manifest['classifications'], self::entryIndex(self::DIFFER, 'ConfigDiffer::buildSyncOnlyResult', '/dev/null'), 1);
        $path = sys_get_temp_dir() . '/waaseyaa-null-device-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
        try {
            [$exit, $stdout, $stderr] = self::runGate(['--manifest=' . $path]);
        } finally {
            unlink($path);
        }
        self::assertSame(1, $exit, $stdout . $stderr);
        self::assertSame('', $stdout);
        self::assertStringContainsString('[unclassified] ' . self::DIFFER . ':', $stderr);
        self::assertStringContainsString('ConfigDiffer::buildSyncOnlyResult "/dev/null"', $stderr);
        self::assertStringContainsString('Its shape fits semantic-diff-marker', $stderr);

        [$exit, , $stderr] = self::runGate(['--manifest=' . $path]);
        self::assertSame(1, $exit, 'A missing manifest fails closed.');
        self::assertStringContainsString('1 violation(s)', $stderr);
        self::assertStringContainsString('cannot read the classification manifest', $stderr);
        self::assertStringNotContainsString('[unclassified]', $stderr, 'Without a manifest the literals are not listed as unclassified.');

        [$exit, , $stderr] = self::runGate(['--baseline']);
        self::assertSame(2, $exit);
        self::assertStringContainsString('unknown argument --baseline', $stderr);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runGate(array $arguments): array
    {
        $gate = new Process([PHP_BINARY, self::GATE, ...$arguments], self::$root);
        $gate->setTimeout(120.0);
        $gate->run();

        return [(int) $gate->getExitCode(), $gate->getOutput(), $gate->getErrorOutput()];
    }

    /**
     * Analyse the tracked scan with some sources replaced and, optionally,
     * another manifest.
     *
     * @param array<string, string> $sources
     *
     * @return list<array<string, mixed>>
     */
    private static function analyze(array $sources = [], mixed $manifest = null): array
    {
        return \pnd_analyze(
            self::$scan['files'],
            array_replace(self::$scan['sources'], $sources),
            func_num_args() > 1 ? $manifest : self::$manifest,
        )['violations'];
    }

    private static function source(string $path): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(self::$root . '/' . $path));
    }

    private static function mutate(string $source, string $search, string $replace): string
    {
        self::assertSame(1, substr_count($source, $search), "The mutant anchor must occur exactly once:\n{$search}");

        return str_replace($search, $replace, $source);
    }

    /**
     * The violation kinds, sorted, so an assertion does not depend on report order.
     *
     * @param list<array<string, mixed>> $violations
     *
     * @return list<string>
     */
    private static function kinds(array $violations): array
    {
        $kinds = array_column($violations, 'kind');
        sort($kinds, SORT_STRING);

        return $kinds;
    }

    /**
     * The manifest with every suggestion in $violations applied, as a
     * maintainer would paste it: a new entry goes in sorted position, and a
     * raised count replaces the old one.
     *
     * @param array<string, mixed> $manifest
     * @param list<array<string, mixed>> $violations
     *
     * @return array<string, mixed>
     */
    private static function applySuggestions(array $manifest, array $violations): array
    {
        foreach ($violations as $violation) {
            $suggestion = $violation['suggestion'] ?? null;
            if (isset($suggestion['raise'])) {
                $manifest['classifications'][$suggestion['raise']]['occurrences'] = $suggestion['to'];
            } elseif (isset($suggestion['add'])) {
                $manifest['classifications'][] = [...$suggestion['add'], 'rationale' => 'Pasted from the gate suggestion.'];
            }
        }
        $manifest['classifications'] = self::sorted($manifest['classifications']);

        return $manifest;
    }

    /**
     * @param list<array<string, mixed>> $violations
     */
    private static function assertRejectedFor(array $violations, string $kind, string $reason, string $case): void
    {
        foreach (self::byKind($violations, $kind) as $violation) {
            if (str_contains($violation['message'], $reason)) {
                return;
            }
        }
        self::fail("{$case}: expected a {$kind} violation for \"{$reason}\"; got:\n" . \pnd_format_violations($violations, \PND_MANIFEST));
    }

    /**
     * @param list<array<string, mixed>> $violations
     *
     * @return list<array<string, mixed>>
     */
    private static function byKind(array $violations, string $kind): array
    {
        return array_values(array_filter($violations, static fn(array $violation): bool => $violation['kind'] === $kind));
    }

    private static function entryIndex(string $file, string $symbol, string $literal): int
    {
        foreach (self::$manifest['classifications'] as $index => $entry) {
            if ([$entry['file'], $entry['symbol'], $entry['literal']] === [$file, $symbol, $literal]) {
                return $index;
            }
        }
        self::fail("No tracked classification for {$file} {$symbol} {$literal}.");
    }

    /**
     * @param list<array<string, mixed>> $classifications
     *
     * @return array<string, mixed>
     */
    private static function withClassifications(array $classifications): array
    {
        return [...self::$manifest, 'classifications' => $classifications];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private static function withClassification(array $manifest, array $classification): array
    {
        return [...$manifest, 'classifications' => self::sorted([...$manifest['classifications'], $classification])];
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array<string, mixed>
     */
    private static function withEntryAt(int $index, array $entry): array
    {
        $manifest = self::$manifest;
        $manifest['classifications'][$index] = $entry;

        return $manifest;
    }

    /**
     * @param list<array<string, mixed>> $classifications
     *
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $classifications): array
    {
        usort(
            $classifications,
            static fn(array $left, array $right): int => strcmp($left['file'], $right['file'])
                ?: strcmp($left['symbol'], $right['symbol'])
                ?: strcmp($left['literal'], $right['literal']),
        );

        return $classifications;
    }
}
