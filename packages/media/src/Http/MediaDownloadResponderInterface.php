<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canonical managed-media download response for an already populated request.
 *
 * Resolve through the Media provider. The request must retain its matching
 * AccountInterface `_account` and immutable AuthorizationPrincipalInterface
 * `_authorization_principal`, including tenant/community context. This service
 * grants no access: entity-view and audited source-URI authorization still run.
 * Explicit media ID accepts the canonical numeric ID or UUID. Missing, denied,
 * private/unknown URI and uncontained/missing bytes retain concealed 404s.
 * Caller route attributes and headers are not mutated. No redirect or legacy
 * path fallback is performed. Parent/reference policy remains the caller's duty.
 *
 * @api
 */
interface MediaDownloadResponderInterface
{
    public function download(Request $request, string $mediaId): Response;
}
