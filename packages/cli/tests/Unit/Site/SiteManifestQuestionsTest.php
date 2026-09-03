<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Site\SiteManifestQuestions;

/**
 * The shared identity questions the interactive wizard and the interactive
 * preset path both ask (#2442). The behaviour under test is the refusal rule:
 * `site:init` never guesses an operator decision, and never silently accepts
 * an answer that carries none.
 */
#[CoversClass(SiteManifestQuestions::class)]
final class SiteManifestQuestionsTest extends TestCase
{
    #[Test]
    public function anAnsweredQuestionIsAcceptedWithSurroundingWhitespaceRemoved(): void
    {
        $io = $this->scriptedIo(['  Example Nation  ']);

        self::assertSame(
            'Example Nation',
            SiteManifestQuestions::required($io, 'What is the public name of this application?', 'default-name'),
        );
    }

    #[Test]
    public function anUnansweredQuestionIsOfferedTheDefaultRatherThanLeftEmpty(): void
    {
        $io = $this->scriptedIo([]);

        self::assertSame(
            'APP_ORIGIN',
            SiteManifestQuestions::required($io, 'Which configuration key supplies the canonical production origin?', 'APP_ORIGIN'),
        );
    }

    /**
     * A whitespace-only answer is a refusal to decide, not a decision. It is
     * rejected naming the exact question left unanswered, so an operator is
     * not left guessing which of several prompts to revisit.
     */
    #[Test]
    public function anAnswerCarryingNoDecisionIsRefusedNamingTheQuestion(): void
    {
        $io = $this->scriptedIo(['   ']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A decision is required: What stable application ID should identify it?');

        SiteManifestQuestions::required($io, 'What stable application ID should identify it?', 'example');
    }

    /**
     * Content-type IDs are authored as one comma-separated answer. Blank
     * entries and repeats are operator typing, not additional content types,
     * but the surviving order is the authored order: it becomes the manifest's
     * content-type order and therefore the order of `public_routes`.
     */
    #[Test]
    public function contentTypeIdsAreTrimmedDeduplicatedAndKeptInAuthoredOrder(): void
    {
        self::assertSame(
            ['page', 'article', 'news_item'],
            SiteManifestQuestions::ids(' page , article ,,page,news_item , '),
        );
    }

    #[Test]
    public function anAnswerNamingNoContentTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one public content type is required.');

        SiteManifestQuestions::ids(' , ,');
    }

    /** @param list<string> $answers */
    private function scriptedIo(array $answers): SymfonyCommandIO
    {
        return new class ($answers) extends SymfonyCommandIO {
            /** @param list<string> $answers */
            public function __construct(private array $answers)
            {
                parent::__construct(new ArrayInput([]), new BufferedOutput(), new BufferedOutput());
            }

            public function ask(string $question, ?string $default = null): ?string
            {
                return array_shift($this->answers) ?? $default;
            }
        };
    }
}
