<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class S1SqliteTopologyContractTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function repository_surfaces_match_the_forge_neutral_topology_contract(): void
    {
        self::assertFileExists($this->root . '/support/s1-sqlite-v1.json');
        self::assertFileExists($this->root . '/docs/specs/s1-sqlite-topology.md');
        self::assertTrue(is_executable($this->root . '/bin/check-s1-sqlite-contract'));
        self::assertTrue(is_executable($this->root . '/tests/PackagedForm/check-s1-sqlite-artifact'));

        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root . '/bin/check-s1-sqlite-contract') . ' 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    #[Test]
    public function exact_candidate_contract_survives_an_installed_artifact_boundary(): void
    {
        exec(
            escapeshellarg($this->root . '/tests/PackagedForm/check-s1-sqlite-artifact') . ' 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    #[Test]
    public function checker_rejects_semantic_contract_substitutions(): void
    {
        $canonical = json_decode(
            (string) file_get_contents($this->root . '/support/s1-sqlite-v1.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $mutations = [
            'two application nodes' => static function (array &$contract): void {
                $contract['authority']['application_nodes'] = 2;
            },
            'unbounded busy wait' => static function (array &$contract): void {
                $contract['connection']['busy_timeout_ms'] = 60_000;
            },
            'authoritative search copy' => static function (array &$contract): void {
                $contract['optional_search_projection']['authoritative'] = true;
            },
            'dropped alternate-engine refusal' => static function (array &$contract): void {
                $contract['refused']['alternate_databases'] = ['mysql', 'postgresql'];
            },
            'dropped H1 refusal' => static function (array &$contract): void {
                $contract['refused']['topologies'] = array_values(array_diff(
                    $contract['refused']['topologies'],
                    ['H1'],
                ));
            },
            'widened memory environment' => static function (array &$contract): void {
                $contract['environment']['in_memory_allowed'][] = 'staging';
            },
            'unknown contract authority' => static function (array &$contract): void {
                $contract['authority']['forge'] = 'github';
            },
            'forge as authority' => static function (array &$contract): void {
                $contract['verification']['forge_is_authority'] = true;
            },
            'path repository masks installed artifact' => static function (array &$contract): void {
                $contract['verification']['artifact_uses_path_repository'] = true;
            },
            'unbound dependency bytes' => static function (array &$contract): void {
                $contract['verification']['artifact_binds_dependency_bytes'] = false;
            },
        ];

        foreach ($mutations as $name => $mutate) {
            $contract = $canonical;
            $mutate($contract);
            $path = tempnam(sys_get_temp_dir(), 's1-sqlite-');
            self::assertNotFalse($path);

            try {
                file_put_contents($path, json_encode($contract, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                $output = [];
                exec(
                    escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg($this->root . '/bin/check-s1-sqlite-contract') . ' '
                    . escapeshellarg('--contract=' . $path) . ' 2>&1',
                    $output,
                    $exitCode,
                );

                self::assertNotSame(0, $exitCode, "{$name}: " . implode("\n", $output));
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    #[Test]
    public function checker_rejects_construction_roster_omission_substitution_and_invention(): void
    {
        $canonical = json_decode(
            (string) file_get_contents($this->root . '/support/s1-sqlite-construction-roster.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $mutations = [
            'omitted occurrence' => static function (array &$roster): void {
                array_pop($roster['candidates']);
            },
            'valid class substitution' => static function (array &$roster): void {
                $roster['candidates'][0]['class'] = $roster['candidates'][0]['class'] === 'test'
                    ? 'test-utility'
                    : 'test';
            },
            'invented occurrence' => static function (array &$roster): void {
                $invented = $roster['candidates'][0];
                $invented['line'] = 999_999;
                $roster['candidates'][] = $invented;
            },
            'weakened query' => static function (array &$roster): void {
                unset($roster['patterns']['pdo_constructor']);
            },
        ];

        foreach ($mutations as $name => $mutate) {
            $roster = $canonical;
            $mutate($roster);
            $path = tempnam(sys_get_temp_dir(), 's1-sqlite-roster-');
            self::assertNotFalse($path);
            try {
                file_put_contents($path, json_encode($roster, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                $output = [];
                exec(
                    escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg($this->root . '/bin/check-s1-sqlite-contract') . ' '
                    . escapeshellarg('--roster=' . $path) . ' 2>&1',
                    $output,
                    $exitCode,
                );
                self::assertNotSame(0, $exitCode, "{$name}: " . implode("\n", $output));
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    #[Test]
    public function construction_classifier_does_not_auto_approve_new_serving_paths(): void
    {
        $checker = (string) file_get_contents($this->root . '/bin/check-s1-sqlite-contract');

        self::assertStringContainsString('s1SqliteConstructionClass(string $path, string $patternId)', $checker);
        self::assertStringContainsString("\$reviewed[\$path . '|' . \$patternId] ?? 'unclassified'", $checker);
        self::assertStringNotContainsString("str_starts_with(\$path, 'packages/cli/src/')", $checker);
        self::assertStringContainsString('phpShebang', $checker);
    }

    #[Test]
    public function packaged_archive_normalizes_permissions_across_runner_identities(): void
    {
        $script = (string) file_get_contents($this->root . '/tests/PackagedForm/check-s1-sqlite-artifact');

        self::assertStringContainsString('--no-same-owner --no-same-permissions', $script);
        self::assertSame(2, substr_count($script, '--mode=u=rwX,go=rX'));
    }

    #[Test]
    public function checker_rejects_dependency_identity_byte_omission_and_invention(): void
    {
        $canonical = json_decode(
            (string) file_get_contents($this->root . '/support/s1-sqlite-dependency-bytes.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $mutations = [
            'dependency byte drift' => static function (array &$authority): void {
                $authority['dependencies'][0]['bytes'] = str_repeat('0', 64);
            },
            'valid alternate version' => static function (array &$authority): void {
                $authority['dependencies'][0]['version'] = '99.0.0';
            },
            'missing dependency' => static function (array &$authority): void {
                array_pop($authority['dependencies']);
            },
            'extra dependency' => static function (array &$authority): void {
                $extra = $authority['dependencies'][0];
                $extra['name'] = 'example/extra';
                $authority['dependencies'][] = $extra;
            },
        ];

        foreach ($mutations as $name => $mutate) {
            $authority = $canonical;
            $mutate($authority);
            $path = tempnam(sys_get_temp_dir(), 's1-sqlite-dependencies-');
            self::assertNotFalse($path);
            try {
                file_put_contents($path, json_encode($authority, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                $output = [];
                exec(
                    escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg($this->root . '/bin/check-s1-sqlite-contract') . ' '
                    . escapeshellarg('--dependencies=' . $path) . ' 2>&1',
                    $output,
                    $exitCode,
                );
                self::assertNotSame(0, $exitCode, "{$name}: " . implode("\n", $output));
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
