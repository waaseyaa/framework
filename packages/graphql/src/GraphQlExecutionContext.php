<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL;

use Waaseyaa\GraphQL\Resolver\EntityResolver;
use Waaseyaa\GraphQL\Resolver\ReferenceLoader;

/**
 * Per-request GraphQL execution context.
 *
 * R12 (audit A10, SECURITY): SchemaFactory caches the built Schema across
 * requests (a deliberate worker-mode optimization, see SchemaFactory
 * $schemaCache). For that cache to be safe, the Schema itself must be pure
 * structure -- its resolver closures must never capture per-request,
 * account-bound collaborators. This holder carries the two collaborators
 * that previously WERE captured by closure (EntityResolver, ReferenceLoader)
 * so they instead arrive as the GraphQL execution contextValue (the 3rd
 * resolver argument), constructed fresh per request in
 * {@see GraphQlEndpoint::handle()} and passed to `GraphQL::executeQuery()`.
 * A single cached Schema can then be reused safely across requests/accounts:
 * each execution reads ITS OWN context, never a previous request's.
 */
final class GraphQlExecutionContext
{
    public function __construct(
        public readonly EntityResolver $entityResolver,
        public readonly ReferenceLoader $referenceLoader,
    ) {}
}
