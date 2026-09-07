<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Provider;

/**
 * Selects Google's `access_type` authorization parameter.
 *
 * `Offline` requests refresh-token eligibility; issuance depends on Google
 * grant state and policy and is not guaranteed on each exchange. See
 * https://developers.google.com/identity/protocols/oauth2/web-server
 *
 * @api
 */
enum GoogleAccessType: string
{
    case Offline = 'offline';
    case Online = 'online';
}
