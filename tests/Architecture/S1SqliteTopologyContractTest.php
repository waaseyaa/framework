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
            'forge as authority' => static function (array &$contract): void {
                $contract['verification']['forge_is_authority'] = true;
            },
            'path repository masks installed artifact' => static function (array &$contract): void {
                $contract['verification']['artifact_uses_path_repository'] = true;
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
}
