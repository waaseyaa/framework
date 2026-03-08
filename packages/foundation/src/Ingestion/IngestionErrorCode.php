<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Ingestion;

/**
 * Canonical error codes for ingestion failures.
 *
 * Organized by phase: ENVELOPE_* for envelope-level validation,
 * PAYLOAD_* for content-type schema validation, PIPELINE_* for
 * pipeline-level failures.
 */
enum IngestionErrorCode: string
{
    // Envelope validation
    case ENVELOPE_FIELD_MISSING       = 'ENVELOPE_FIELD_MISSING';
    case ENVELOPE_FIELD_TYPE_INVALID  = 'ENVELOPE_FIELD_TYPE_INVALID';
    case ENVELOPE_FIELD_EMPTY         = 'ENVELOPE_FIELD_EMPTY';
    case ENVELOPE_FIELD_UNKNOWN       = 'ENVELOPE_FIELD_UNKNOWN';
    case ENVELOPE_TIMESTAMP_INVALID   = 'ENVELOPE_TIMESTAMP_INVALID';
    case ENVELOPE_TRACE_ID_INVALID    = 'ENVELOPE_TRACE_ID_INVALID';

    // Payload validation
    case PAYLOAD_SCHEMA_NOT_FOUND     = 'PAYLOAD_SCHEMA_NOT_FOUND';
    case PAYLOAD_SCHEMA_LOAD_FAILED   = 'PAYLOAD_SCHEMA_LOAD_FAILED';
    case PAYLOAD_FIELD_MISSING        = 'PAYLOAD_FIELD_MISSING';
    case PAYLOAD_FIELD_TYPE_INVALID   = 'PAYLOAD_FIELD_TYPE_INVALID';
    case PAYLOAD_FIELD_TOO_SHORT      = 'PAYLOAD_FIELD_TOO_SHORT';
    case PAYLOAD_FIELD_TOO_LONG       = 'PAYLOAD_FIELD_TOO_LONG';
    case PAYLOAD_FIELD_READ_ONLY      = 'PAYLOAD_FIELD_READ_ONLY';
    case PAYLOAD_FIELD_UNKNOWN        = 'PAYLOAD_FIELD_UNKNOWN';
}
