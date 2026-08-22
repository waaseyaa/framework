<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Discovery;

use Waaseyaa\Entity\EntityInterface;

/**
 * Application-owned canonical URL policy for the crawler-facing surfaces.
 *
 * Both methods return a ROOT-RELATIVE path, never an absolute URL. The trusted
 * public origin is framework-owned ({@see \Waaseyaa\SSR\Http\CanonicalPublicOrigin},
 * sourced only from application configuration) and is prepended by the framework,
 * so an application policy cannot influence the origin of an emitted URL and a
 * request `Host` / `Forwarded` / `X-Forwarded-Host` header can never reach it.
 *
 * Returning `null` means "this entity has no such public URL" and omits it from
 * the surface. A returned value that is not a valid root-relative path is
 * rejected and the entity is likewise omitted: the framework never falls back to
 * its own built-in `/{entityTypeId}/{id}` URL model for an entity whose policy
 * declined or misbehaved, because that would advertise a URL the application did
 * not authorise. See {@see DiscoveryFailurePolicy} for how a THROWING policy is
 * treated.
 *
 * Bind an implementation on this interface's FQCN in a service provider's
 * `register()` (the same container convention as
 * {@see \Waaseyaa\Search\ProvidesEntitySearchProjectorsInterface}). Binding
 * nothing keeps the framework's documented zero-config default.
 *
 * @api
 */
interface PublicUrlPolicyInterface
{
    /**
     * The entity's canonical public page path, e.g. `/news/spring-gathering`.
     *
     * Used for `sitemap.xml`. Must be root-relative and MUST NOT carry a query
     * string or fragment. Return null when the entity is not publicly addressable.
     */
    public function canonicalPath(EntityInterface $entity): ?string;

    /**
     * The path that returns this entity's Markdown representation, e.g.
     * `/node/97?format=md`.
     *
     * Used for `llms.txt`, whose contract is that every linked URL returns clean
     * Markdown. This is deliberately a SEPARATE question from
     * {@see self::canonicalPath()}: an application's canonical human-facing URL
     * is frequently served by a controller that renders HTML only, in which case
     * the Markdown representation lives on a different route. Must be
     * root-relative; a query string is permitted, a fragment is not. Return null
     * when the entity has no Markdown representation.
     */
    public function markdownPath(EntityInterface $entity): ?string;
}
