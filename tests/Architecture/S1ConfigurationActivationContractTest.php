<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class S1ConfigurationActivationContractTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function exact_activation_contract_and_complete_boundary_roster_pass(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root . '/bin/check-s1-configuration-activation') . ' 2>&1', $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringContainsString('one transactional authority', implode("\n", $output));
    }

    #[Test]
    public function checker_rejects_omission_valid_class_substitution_and_weakened_query(): void
    {
        $canonical = json_decode((string) file_get_contents($this->root . '/support/s1-configuration-activation-roster.json'), true, flags: JSON_THROW_ON_ERROR);
        $mutations = [
            'omitted occurrence' => static function (array &$roster): void {
                array_pop($roster['candidates']);
            },
            'valid class substitution' => static function (array &$roster): void {
                $roster['candidates'][0]['class'] = $roster['candidates'][0]['class'] === 'activation-authority' ? 'active-reader' : 'activation-authority';
            },
            'weakened query' => static function (array &$roster): void {
                unset($roster['patterns']['activation_table']);
            },
        ];
        foreach ($mutations as $name => $mutate) {
            $roster = $canonical;
            $mutate($roster);
            $path = tempnam(sys_get_temp_dir(), 's1-cfg02-roster-');
            self::assertNotFalse($path);
            try {
                file_put_contents($path, json_encode($roster, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root . '/bin/check-s1-configuration-activation') . ' ' . escapeshellarg('--roster=' . $path) . ' 2>&1', $output, $exitCode);
                self::assertNotSame(0, $exitCode, $name . ': ' . implode("\n", $output));
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    #[Test]
    public function checker_rejects_weakened_or_unknown_contract_semantics(): void
    {
        $canonical = json_decode((string) file_get_contents($this->root . '/support/s1-configuration-activation-v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $mutations = [
            static function (array &$contract): void {
                $contract['ordinary_absence'] = 'delete';
            },
            static function (array &$contract): void {
                $contract['blind_force'] = 'supported';
            },
            static function (array &$contract): void {
                $contract['transaction_first_operation'] = 'read';
            },
            static function (array &$contract): void {
                $contract['unknown'] = true;
            },
        ];
        foreach ($mutations as $mutate) {
            $contract = $canonical;
            $mutate($contract);
            $path = tempnam(sys_get_temp_dir(), 's1-cfg02-contract-');
            self::assertNotFalse($path);
            try {
                file_put_contents($path, json_encode($contract, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root . '/bin/check-s1-configuration-activation') . ' ' . escapeshellarg('--contract=' . $path) . ' 2>&1', $output, $exitCode);
                self::assertNotSame(0, $exitCode, implode("\n", $output));
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    #[Test]
    public function production_classifier_fails_closed_for_new_paths(): void
    {
        $checker = (string) file_get_contents($this->root . '/bin/check-s1-configuration-activation');
        self::assertStringContainsString("return \$classes[\$path] ?? 'unclassified';", $checker);
    }

    #[Test]
    public function exact_head_archives_prove_cas_rollback_and_worker_epoch_reconciliation(): void
    {
        $script = $this->root . '/tests/PackagedForm/check-s1-configuration-archives';
        exec(escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringContainsString('exact-head archive proof passed', implode("\n", $output));
    }
}
