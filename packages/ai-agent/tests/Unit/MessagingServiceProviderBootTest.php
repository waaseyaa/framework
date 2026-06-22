<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\MessagingServiceProvider;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;
use Waaseyaa\AI\Agent\Provider\NullLlmProvider;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

#[CoversClass(MessagingServiceProvider::class)]
final class MessagingServiceProviderBootTest extends TestCase
{
    #[Test]
    public function bootWarnsWhenEffectiveProviderIsNullLlmProvider(): void
    {
        $warnings = [];
        $logger = $this->recordingLogger($warnings);

        $provider = new MessagingServiceProvider();
        $provider->setKernelServices($this->kernelServices($logger, new NullLlmProvider()));
        $provider->register();
        $provider->boot();

        self::assertCount(1, $warnings);
        self::assertStringContainsString('No LLM provider configured', $warnings[0]);
        self::assertStringContainsString(ProviderInterface::class, $warnings[0]);
    }

    #[Test]
    public function bootStaysSilentWhenAppRebindsRealProvider(): void
    {
        $warnings = [];
        $logger = $this->recordingLogger($warnings);

        $realProvider = new class implements ProviderInterface {
            public function sendMessage(MessageRequest $request): MessageResponse
            {
                return new MessageResponse(content: [], stopReason: 'end_turn');
            }
        };

        $provider = new MessagingServiceProvider();
        $provider->setKernelServices($this->kernelServices($logger, $realProvider));
        $provider->register();
        $provider->boot();

        self::assertSame([], $warnings);
    }

    /**
     * @param array<int, string> $warnings
     */
    private function recordingLogger(array &$warnings): LoggerInterface
    {
        return new class ($warnings) implements LoggerInterface {
            /** @param array<int, string> $warnings */
            public function __construct(private array &$warnings) {}

            public function emergency(string|\Stringable $message, array $context = []): void {}
            public function alert(string|\Stringable $message, array $context = []): void {}
            public function critical(string|\Stringable $message, array $context = []): void {}
            public function error(string|\Stringable $message, array $context = []): void {}

            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->warnings[] = (string) $message;
            }

            public function notice(string|\Stringable $message, array $context = []): void {}
            public function info(string|\Stringable $message, array $context = []): void {}
            public function debug(string|\Stringable $message, array $context = []): void {}

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void {}
        };
    }

    private function kernelServices(LoggerInterface $logger, ProviderInterface $provider): KernelServicesInterface
    {
        return new class ($logger, $provider) implements KernelServicesInterface {
            public function __construct(
                private readonly LoggerInterface $logger,
                private readonly ProviderInterface $provider,
            ) {}

            public function get(string $abstract): ?object
            {
                if ($abstract === LoggerInterface::class) {
                    return $this->logger;
                }
                if ($abstract === ProviderInterface::class) {
                    return $this->provider;
                }

                return null;
            }
        };
    }
}
