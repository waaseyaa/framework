<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CloverCoverageMergerTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/waaseyaa-clover-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fixtureRoot . '/*') ?: [] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->fixtureRoot);
    }

    #[Test]
    public function itMergesShardCoverageByMaximumStatementCount(): void
    {
        $first = $this->writeClover('first.xml', [10 => 1, 11 => 0]);
        $second = $this->writeClover('second.xml', [10 => 0, 11 => 3]);
        $output = $this->fixtureRoot . '/merged.xml';

        $result = $this->runMerger($output, $first, $second);

        self::assertSame(0, $result['exit'], $result['error']);
        self::assertStringContainsString('2/2 executable lines covered', $result['output']);
        $xml = simplexml_load_file($output, options: LIBXML_NONET | LIBXML_NOBLANKS);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        $counts = [];
        foreach ($xml->xpath('//file/line') ?: [] as $line) {
            $counts[(int) $line['num']] = (int) $line['count'];
        }
        self::assertSame([10 => 1, 11 => 3], $counts);
    }

    #[Test]
    public function itRejectsDuplicateAndSymlinkedShardInputs(): void
    {
        $first = $this->writeClover('first.xml', [10 => 1]);
        $output = $this->fixtureRoot . '/merged.xml';
        $duplicate = $this->runMerger($output, $first, $first);
        self::assertSame(2, $duplicate['exit']);
        self::assertStringContainsString('unique shard artifacts', $duplicate['error']);

        $second = $this->writeClover('second.xml', [11 => 1]);
        $link = $this->fixtureRoot . '/linked.xml';
        if (!symlink($second, $link)) {
            self::markTestSkipped('Symlinks are unavailable.');
        }
        $unsafe = $this->runMerger($output, $first, $link);
        self::assertSame(2, $unsafe['exit']);
        self::assertStringContainsString('unavailable or unsafe', $unsafe['error']);
    }

    /** @param array<int, int> $counts */
    private function writeClover(string $name, array $counts): string
    {
        $lines = '';
        foreach ($counts as $number => $count) {
            $lines .= sprintf('<line num="%d" type="stmt" count="%d"/>', $number, $count);
        }
        $path = $this->fixtureRoot . '/' . $name;
        file_put_contents($path, sprintf(
            '<?xml version="1.0"?><coverage><project><file name="/repo/packages/demo/src/Thing.php">%s</file></project></coverage>',
            $lines,
        ));

        return $path;
    }

    /** @return array{exit: int, output: string, error: string} */
    private function runMerger(string $output, string ...$inputs): array
    {
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/merge-clover-coverage', '--output=' . $output, ...$inputs];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => (string) $stdout, 'error' => (string) $stderr];
    }
}
