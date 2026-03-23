<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

interface StreamingProviderInterface extends ProviderInterface
{
    /**
     * Stream a message, calling $onChunk for each partial result.
     *
     * Returns the complete MessageResponse after the stream ends.
     *
     * @param callable(StreamChunk): void $onChunk
     */
    public function streamMessage(MessageRequest $request, callable $onChunk): MessageResponse;
}
