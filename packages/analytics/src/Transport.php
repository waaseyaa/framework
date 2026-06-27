<?php

declare(strict_types=1);

namespace Waaseyaa\Analytics;

/**
 * HTTP transport seam for UmamiClient.
 *
 * Implementors POST a JSON body to the given URL and return the raw response
 * body on success, or false/null on failure. The return value is advisory only —
 * UmamiClient is fire-and-forget and treats any falsy return as a loggable
 * failure without rethrowing.
 *
 * @api
 */
interface Transport
{
    /**
     * POST $body to $url and return the raw response body, or false on failure.
     */
    public function post(string $url, string $body): string|false;
}
