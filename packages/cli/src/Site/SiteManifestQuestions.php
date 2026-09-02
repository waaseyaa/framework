<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * The plain-language question helpers `SiteManifestWizard` and
 * `SitePresetResolver` both use so identity questions ask the same wording
 * and enforce the same "a decision is required" rule regardless of which
 * one-time answer path the operator took.
 */
final class SiteManifestQuestions
{
    public static function required(SymfonyCommandIO $io, string $question, string $default): string
    {
        $answer = trim((string) $io->ask($question, $default));
        if ($answer === '') {
            throw new \InvalidArgumentException("A decision is required: {$question}");
        }

        return $answer;
    }

    /** @return list<string> */
    public static function ids(string $input): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $input)),
            static fn(string $id): bool => $id !== '',
        )));
        if ($ids === []) {
            throw new \InvalidArgumentException('At least one public content type is required.');
        }

        return $ids;
    }
}
