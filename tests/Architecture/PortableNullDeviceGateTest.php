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
        }

        $hostDerived = "<?php\nproc_open(\$command, [0 => ['null'], 1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], \$pipes);\n";
        $occurrences = \pnd_occurrences(self::SYNTHETIC, $hostDerived);
        self::assertCount(1, $occurrences);
        self::assertFalse($occurrences[0]['descriptor']);
        self::assertSame('platform-derived', \pnd_fitting_purpose($occurrences[0]));

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
        self::assertStringContainsString('one more occurrence than the 1 classified', $violations[0]['message']);
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
        $index = self::entryIndex(self::DIFFER, 'ConfigDiffer::buildSyncOnlyResult', '/dev/null');
        $entry = static fn(array $changes): array => array_replace(self::$manifest['classifications'][$index], $changes);
        $broad = [
            'a glob' => ['file' => 'packages/config/src/Sync/*.php'],
            'a directory' => ['file' => 'packages/config/src/Sync'],
            'a trailing slash' => ['file' => 'packages/config/src/Sync/'],
            'a symbol pattern' => ['symbol' => 'ConfigDiffer::*'],
        ];
        foreach ($broad as $case => $changes) {
            $violations = self::analyze([], self::withEntryAt($index, $entry($changes)));
            self::assertContains('overly-broad', self::kinds($violations), $case);
            self::assertNotContains('purpose-mismatch', self::kinds($violations), $case);
        }

        $malformed = [
            'an absolute path' => ['file' => '/packages/config/src/Sync/ConfigDiffer.php'],
            'a drive path' => ['file' => 'C:/packages/config/src/Sync/ConfigDiffer.php'],
            'backslashes' => ['file' => 'packages\\config\\src\\Sync\\ConfigDiffer.php'],
            'a dot segment' => ['file' => 'packages/config/../config/src/Sync/ConfigDiffer.php'],
            'a test file' => ['file' => 'tests/Architecture/PortableNullDeviceGateTest.php'],
            'a vendor file' => ['file' => 'vendor/acme/lib/src/Lib.php'],
            'an empty symbol' => ['symbol' => ''],
            'a malformed symbol' => ['symbol' => 'ConfigDiffer->buildSyncOnlyResult'],
            'a literal without the device' => ['literal' => 'b/'],
            'zero occurrences' => ['occurrences' => 0],
            'a string count' => ['occurrences' => '1'],
            'an unknown purpose' => ['purpose' => 'legacy'],
            'an empty rationale' => ['rationale' => '  '],
            'a multi-line rationale' => ['rationale' => "line one\nline two"],
            'an overlong rationale' => ['rationale' => str_repeat('x', 501)],
        ];
        foreach ($malformed as $case => $changes) {
            self::assertContains('malformed', self::kinds(self::analyze([], self::withEntryAt($index, $entry($changes)))), $case);
        }

        $shapes = [
            'an unknown entry key' => self::withEntryAt($index, [...$entry([]), 'line' => 210]),
            'a missing entry key' => self::withEntryAt($index, array_diff_key($entry([]), ['rationale' => true])),
            'a list entry' => self::withEntryAt($index, ['file', 'symbol']),
            'unsorted entries' => self::withClassifications(array_reverse(self::$manifest['classifications'])),
            'another schema' => [...self::$manifest, 'schema' => 'waaseyaa.other'],
            'another schema version' => [...self::$manifest, 'schema_version' => 2],
            'an unknown manifest key' => [...self::$manifest, 'exemptions' => []],
            'a missing statement' => array_diff_key(self::$manifest, ['statement' => true]),
            'a purpose vocabulary that drifted' => [...self::$manifest, 'purposes' => ['platform-derived' => 'x', 'legacy' => 'y']],
            'classifications that are not a list' => [...self::$manifest, 'classifications' => ['a' => $entry([])]],
            'a list for a manifest' => [self::$manifest],
            'a scalar for a manifest' => 'classifications',
        ];
        foreach ($shapes as $case => $manifest) {
            self::assertContains('malformed', self::kinds(self::analyze([], $manifest)), $case);
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
    public function posix_only_shell_fragments_are_accepted_only_as_redirections_without_a_nul_counterpart(): void
    {
        $recipe = 'packages/deployer/recipe/waaseyaa.php';
        $occurrences = \pnd_occurrences($recipe, self::$scan['sources'][$recipe]);
        self::assertCount(2, $occurrences);
        foreach ($occurrences as $occurrence) {
            self::assertSame('{main}', $occurrence['symbol'], 'A closure belongs to the symbol that encloses it.');
            self::assertSame('posix-only-shell', \pnd_fitting_purpose($occurrence));
        }

        foreach (['cmd >/dev/null 2>&1', 'cmd &> /dev/null', 'cmd >> /dev/null', 'cmd < /dev/null'] as $fragment) {
            $occurrence = \pnd_occurrences(self::SYNTHETIC, "<?php\nexec('{$fragment}');\n")[0];
            self::assertSame('posix-only-shell', \pnd_fitting_purpose($occurrence), $fragment);
        }

        $paired = \pnd_occurrences(self::SYNTHETIC, "<?php\nexec(\$windows ? 'cmd 2>NUL' : 'cmd 2>/dev/null');\n")[0];
        self::assertNotNull(\pnd_purpose_misfit('posix-only-shell', $paired), 'A host pair must be classified as platform-derived.');
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

        self::assertTrue(\pnd_is_php_source('bin/check-portable-null-device', "#!/usr/bin/env php\n<?php\n"));
        self::assertTrue(\pnd_is_php_source('bin/check-portable-null-device', "#!/usr/bin/env php\r\n<?php\r\n"));
        self::assertTrue(\pnd_is_php_source('packages/cli/stubs/job.stub', "<?php\n"));
        self::assertTrue(\pnd_is_php_source('packages/demo/src/Demo.php', ''));
        self::assertFalse(\pnd_is_php_source('bin/check-no-secrets', "#!/usr/bin/env bash\nset -euo pipefail\n"));
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

        // A CRLF checkout (core.autocrlf on the hosted Windows runner) must
        // scan exactly as an LF one, including a literal that spans lines,
        // whose token would otherwise carry the carriage returns.
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
        self::assertStringContainsString('cannot read the classification manifest', $stderr);

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
