<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Discovery\Exception;

use RuntimeException;

/**
 * A crawler surface cannot be built because its configuration is incomplete.
 *
 * Raised when an application has bound a
 * {@see \Waaseyaa\Seo\Discovery\PublicUrlPolicyInterface} (opting into absolute
 * canonical URLs) but no trusted public origin is available to join those paths
 * to. It is deliberately an exception rather than a degradation to relative URLs:
 * quietly emitting a different URL shape than the application asked for is how a
 * misconfiguration turns into wrong canonical URLs that search engines then
 * index.
 *
 * Whether it reaches the client as an HTTP 500 or is absorbed into an empty
 * document is {@see \Waaseyaa\Seo\Discovery\DiscoveryFailurePolicy}'s decision.
 * Under either policy no URL is emitted, so visibility can only narrow.
 *
 * @api
 */
final class DiscoveryConfigurationException extends RuntimeException {}
