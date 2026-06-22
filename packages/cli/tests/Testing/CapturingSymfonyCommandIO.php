<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Testing;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

final class CapturingSymfonyCommandIO extends SymfonyCommandIO
{
    /** @var list<string> */
    private array $stdoutLines = [];

    /** @var list<string> */
    private array $stderrLines = [];

    /**
     * @param array<string, mixed> $options
     */
    private array $capturedOptions;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->capturedOptions = $options;
        parent::__construct(new ArrayInput([]), new BufferedOutput(), new BufferedOutput());
    }

    public function option(string $name): string|int|float|bool|array|null
    {
        return $this->capturedOptions[$name] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->capturedOptions;
    }

    public function write(string $text): void
    {
        if ($text !== '') {
            $this->stdoutLines[] = $text;
        }
    }

    public function writeln(string $text = ''): void
    {
        $this->stdoutLines[] = $text;
    }

    public function error(string $line): void
    {
        $this->stderrLines[] = $line;
    }

    /**
     * @return list<string>
     */
    public function outputLines(): array
    {
        return $this->stdoutLines;
    }

    /**
     * @return list<string>
     */
    public function errorLines(): array
    {
        return $this->stderrLines;
    }

    public function stdout(): string
    {
        return implode("\n", $this->stdoutLines);
    }

    public function stderr(): string
    {
        return implode("\n", $this->stderrLines);
    }
}
