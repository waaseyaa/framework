<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

use Symfony\Component\Messenger\MessageBusInterface;

final class MessageBusQueue implements QueueInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ?QueuePayloadDeprecationDiagnostic $payloadDiagnostic = null,
    ) {}

    public function dispatch(object $message): void
    {
        $this->payloadDiagnostic?->inspect($message);
        $this->messageBus->dispatch($message);
    }
}
