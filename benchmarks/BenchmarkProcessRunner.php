<?php

declare(strict_types=1);

namespace Waaseyaa\Benchmark;

/** @internal Real-page benchmark subprocess boundary. */
final class BenchmarkProcessRunner
{
    /** @param list<string> $command @return array{exit_code:int,stdout:string,stderr:string} */
    public static function run(array $command, string $cwd): array
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start benchmark subprocess.');
        }

        $outputs = [1 => '', 2 => ''];
        $openPipes = [1 => $pipes[1], 2 => $pipes[2]];
        try {
            foreach ($openPipes as $pipe) {
                if (!stream_set_blocking($pipe, false)) {
                    throw new \RuntimeException('Could not configure benchmark subprocess pipe.');
                }
            }

            while ($openPipes !== []) {
                $read = array_values($openPipes);
                $write = null;
                $except = null;
                if (stream_select($read, $write, $except, 1) === false) {
                    throw new \RuntimeException('Could not read benchmark subprocess output.');
                }

                foreach ($read as $pipe) {
                    $descriptor = array_search($pipe, $openPipes, true);
                    if (!is_int($descriptor)) {
                        continue;
                    }
                    $chunk = stream_get_contents($pipe);
                    if ($chunk === false) {
                        throw new \RuntimeException('Could not read benchmark subprocess output.');
                    }
                    $outputs[$descriptor] .= $chunk;
                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($openPipes[$descriptor]);
                    }
                }
            }
        } catch (\Throwable $e) {
            foreach ($openPipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
            throw $e;
        }

        return ['exit_code' => proc_close($process), 'stdout' => $outputs[1], 'stderr' => $outputs[2]];
    }
}
