<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

interface ProviderInterface
{
    public function sendMessage(MessageRequest $request): MessageResponse;
}
