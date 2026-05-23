<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Listener;

use PHPUnit\Event\Test\Errored as TestErroredEvent;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed as TestFailedEvent;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\MarkedIncomplete as TestMarkedIncompleteEvent;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;
use PHPUnit\Event\Test\Passed as TestPassedEvent;
use PHPUnit\Event\Test\PassedSubscriber;
use PHPUnit\Event\Test\Skipped as TestSkippedEvent;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Waaseyaa\AgentOutput\Formatter\PhpUnitFormatter;

/**
 * PHPUnit 10 extension that emits a `Waaseyaa\AgentOutput\Formatter\PhpUnitFormatter`
 * NDJSON envelope on the last line of stdout when the agent-output signal
 * is present.
 *
 * Activation: `WAASEYAA_OUTPUT=json` env var. PHPUnit's extension API
 * doesn't surface custom CLI flags, so the env var is the canonical
 * trigger (the `bin/check-*` wrapper pattern's `--output=json` flag is
 * inapplicable here). When the env var is unset the extension is a
 * no-op — PHPUnit's default human output is preserved (C-002).
 *
 * Output discipline: the envelope is appended to stdout at the
 * `ExecutionFinished` event so it lands on a single line AFTER
 * PHPUnit's standard footer. Agent consumers read the file
 * line-by-line and parse the trailing line that starts with
 * `{"tool":"phpunit"`.
 *
 * Registered via the `<extensions>` block in `phpunit.xml.dist`.
 *
 * M4 WP04D of mission `agent-output-package-01KS5VX1`.
 *
 * @api
 */
final class AgentOutputPhpUnitExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (getenv('WAASEYAA_OUTPUT') !== 'json') {
            return;
        }

        $state = new PhpUnitRunState();

        $facade->registerSubscribers(
            new class ($state) implements PassedSubscriber {
                public function __construct(private readonly PhpUnitRunState $state) {}

                public function notify(TestPassedEvent $event): void
                {
                    $this->state->passed++;
                }
            },
            new class ($state) implements FailedSubscriber {
                public function __construct(private readonly PhpUnitRunState $state) {}

                public function notify(TestFailedEvent $event): void
                {
                    $this->state->failed++;
                    $this->state->failures[] = [
                        'test' => $event->test()->id(),
                        'file' => method_exists($event->test(), 'file') ? (string) $event->test()->file() : '',
                        'line' => 0,
                        'message' => $event->throwable()->message(),
                    ];
                }
            },
            new class ($state) implements ErroredSubscriber {
                public function __construct(private readonly PhpUnitRunState $state) {}

                public function notify(TestErroredEvent $event): void
                {
                    $this->state->failed++;
                    $this->state->failures[] = [
                        'test' => $event->test()->id(),
                        'file' => method_exists($event->test(), 'file') ? (string) $event->test()->file() : '',
                        'line' => 0,
                        'message' => $event->throwable()->message(),
                    ];
                }
            },
            new class ($state) implements SkippedSubscriber {
                public function __construct(private readonly PhpUnitRunState $state) {}

                public function notify(TestSkippedEvent $event): void
                {
                    $this->state->skipped++;
                }
            },
            new class ($state) implements MarkedIncompleteSubscriber {
                public function __construct(private readonly PhpUnitRunState $state) {}

                public function notify(TestMarkedIncompleteEvent $event): void
                {
                    $this->state->skipped++;
                }
            },
            new class ($state) implements ExecutionFinishedSubscriber {
                public function __construct(private readonly PhpUnitRunState $state) {}

                public function notify(ExecutionFinished $event): void
                {
                    $envelope = new PhpUnitFormatter()->format([
                        'suite' => null,
                        'passed' => $this->state->passed,
                        'failed' => $this->state->failed,
                        'skipped' => $this->state->skipped,
                        'duration_ms' => null,
                        'failures' => $this->state->failures,
                    ]);

                    // Leading "\n" guarantees the envelope lands on its own
                    // line (PHPUnit's progress-dots line doesn't terminate
                    // with a newline, so the envelope would otherwise be
                    // concatenated with the progress meter).
                    fwrite(STDOUT, "\n" . $envelope);
                }
            },
        );
    }
}
