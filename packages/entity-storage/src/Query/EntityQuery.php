<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Query;

/**
 * @api
 *
 * Marker interface for entity query objects.
 *
 * WP01 stubs this type so that the canonical two-parameter
 * {@see \Waaseyaa\EntityStorage\Backend\FieldStorageBackendGateway::supportsQuery()}
 * signature is correct from the first commit. WP06 enriches this interface
 * with filter, sort, and pagination contracts without touching the backend API.
 *
 * Registrar-owned {@see \Waaseyaa\EntityStorage\Backend\FieldStorageBackendGateway} instances
 * receive this object at definition-validation time and must declare whether
 * they can satisfy the query for a given field. If they cannot, they return
 * false; callers then throw
 * {@see \Waaseyaa\EntityStorage\Exception\UnsupportedQueryException}.
 */
interface EntityQuery {}
