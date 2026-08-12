<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class S1ConfigurationAuthorityContractTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function every_configuration_boundary_is_classified_and_the_semantic_contract_passes(): void
    {
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root . '/bin/check-s1-configuration-authority') . ' 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringContainsString('one authority', implode("\n", $output));
    }

    #[Test]
    public function checker_rejects_omission_valid_class_substitution_and_weakened_query(): void
    {
        $canonical = json_decode(
            (string) file_get_contents($this->root . '/support/s1-configuration-authority-roster.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $mutations = [
            'omitted occurrence' => static function (array &$roster): void {
                array_pop($roster['candidates']);
            },
            'valid class substitution' => static function (array &$roster): void {
                $roster['candidates'][0]['class'] = $roster['candidates'][0]['class'] === 'authority-root'
                    ? 'legacy-compatibility'
                    : 'authority-root';
            },
            'weakened query' => static function (array &$roster): void {
                unset($roster['patterns']['file_storage_construction']);
            },
        ];

        foreach ($mutations as $name => $mutate) {
            $roster = $canonical;
            $mutate($roster);
            $path = tempnam(sys_get_temp_dir(), 's1-config-roster-');
            self::assertNotFalse($path);
            try {
                file_put_contents($path, json_encode($roster, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                exec(
                    escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg($this->root . '/bin/check-s1-configuration-authority') . ' '
                    . escapeshellarg('--roster=' . $path) . ' 2>&1',
                    $output,
                    $exitCode,
                );
                self::assertNotSame(0, $exitCode, $name . ': ' . implode("\n", $output));
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    #[Test]
    public function production_classifier_does_not_auto_approve_new_paths(): void
    {
        $checker = (string) file_get_contents($this->root . '/bin/check-s1-configuration-authority');

        self::assertStringContainsString("return \$classes[\$path] ?? 'unclassified';", $checker);
        self::assertStringNotContainsString("str_starts_with(\$path, 'packages/", $checker);
    }

    #[Test]
    public function checker_rejects_unknown_or_substituted_machine_contract_fields(): void
    {
        $canonical = json_decode(
            (string) file_get_contents($this->root . '/support/s1-configuration-authority-v1.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $mutations = [
            static function (array &$contract): void {
                $contract['production_missing_activation'] = 'fallback';
            },
            static function (array &$contract): void {
                $contract['commands'][0] = 'config:legacy-export';
            },
            static function (array &$contract): void {
                $contract['unknown'] = true;
            },
        ];

        foreach ($mutations as $mutate) {
            $contract = $canonical;
            $mutate($contract);
            $path = tempnam(sys_get_temp_dir(), 's1-config-contract-');
            self::assertNotFalse($path);
            try {
                file_put_contents($path, json_encode($contract, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                exec(
                    escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg($this->root . '/bin/check-s1-configuration-authority') . ' '
                    . escapeshellarg('--contract=' . $path) . ' 2>&1',
                    $output,
                    $exitCode,
                );
                self::assertNotSame(0, $exitCode, implode("\n", $output));
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
