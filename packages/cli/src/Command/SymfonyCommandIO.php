<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * @api
 */
class SymfonyCommandIO
{
    private readonly OutputInterface $stderr;
    private readonly QuestionHelper $questions;

    public function __construct(
        private readonly InputInterface $input,
        private readonly OutputInterface $stdout,
        ?OutputInterface $stderr = null,
        ?QuestionHelper $questions = null,
    ) {
        $this->stderr = $stderr
            ?? ($stdout instanceof ConsoleOutputInterface ? $stdout->getErrorOutput() : $stdout);
        $this->questions = $questions ?? new QuestionHelper();
    }

    public function argument(string $name): string|int|float|bool|array|null
    {
        try {
            return $this->normalizeValue($this->input->getArgument($name));
        } catch (\Throwable) {
            return null;
        }
    }

    public function option(string $name): string|int|float|bool|array|null
    {
        try {
            return $this->normalizeValue($this->input->getOption($name));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        return $this->input->getArguments();
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->input->getOptions();
    }

    public function write(string $text): void
    {
        $this->stdout->write($this->plainText($text), false, OutputInterface::OUTPUT_RAW);
    }

    public function writeln(string $text = ''): void
    {
        $this->stdout->writeln($this->plainText($text), OutputInterface::OUTPUT_RAW);
    }

    public function error(string $line): void
    {
        $this->stderr->writeln($this->plainText($line), OutputInterface::OUTPUT_RAW);
    }

    public function ask(string $question, ?string $default = null): ?string
    {
        if (!$this->input->isInteractive()) {
            $this->stderr->writeln(sprintf('waaseyaa-cli: stdin is not a tty; using default for prompt "%s"', $question));

            return $default;
        }

        $answer = $this->questions->ask($this->input, $this->stderr, new Question($question . ' ', $default));

        return is_string($answer) ? $answer : $default;
    }

    public function confirm(string $question, bool $default = false): bool
    {
        if (!$this->input->isInteractive()) {
            $this->stderr->writeln(sprintf('waaseyaa-cli: stdin is not a tty; using default for prompt "%s"', $question));

            return $default;
        }

        return (bool) $this->questions->ask($this->input, $this->stderr, new ConfirmationQuestion($question . ' ', $default));
    }

    public function isVerbose(): bool
    {
        return $this->stdout->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
    }

    public function isInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    private function normalizeValue(mixed $value): string|int|float|bool|array|null
    {
        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value) || is_array($value)) {
            return $value;
        }

        return (string) $value;
    }

    private function plainText(string $text): string
    {
        return preg_replace('#</?(?:info|comment|question|error|fg=[^>]+|bg=[^>]+|options=[^>]+)>#', '', $text) ?? $text;
    }
}
