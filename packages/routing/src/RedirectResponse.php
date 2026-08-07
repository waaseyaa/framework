<?php

declare(strict_types=1);

namespace Waaseyaa\Routing;

/**
 * Waaseyaa-owned redirect response for application-controller signatures.
 *
 * The Symfony parent keeps transport compatibility with the HTTP kernel while
 * application code can remain on the Waaseyaa public surface.
 *
 * @api
 */
final class RedirectResponse extends \Symfony\Component\HttpFoundation\RedirectResponse {}
