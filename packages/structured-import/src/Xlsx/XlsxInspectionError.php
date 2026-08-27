<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/** @api */
enum XlsxInspectionError: string
{
    case InvalidChecksum = 'invalid_checksum';
    case SourceUnavailable = 'source_unavailable';
    case SourceChecksumMismatch = 'source_checksum_mismatch';
    case SourceTooLarge = 'source_too_large';
    case InvalidMime = 'invalid_mime';
    case InvalidArchive = 'invalid_archive';
    case UnsafeArchiveEntry = 'unsafe_archive_entry';
    case EncryptedEntry = 'encrypted_entry';
    case TooManyEntries = 'too_many_entries';
    case EntryTooLarge = 'entry_too_large';
    case ArchiveTooLarge = 'archive_too_large';
    case CompressionRatioExceeded = 'compression_ratio_exceeded';
    case MissingPackagePart = 'missing_package_part';
    case UnsupportedPackage = 'unsupported_package';
    case UnsafeXml = 'unsafe_xml';
    case XmlTooLarge = 'xml_too_large';
    case XmlTooDeep = 'xml_too_deep';
    case MalformedXml = 'malformed_xml';
    case ExternalRelationship = 'external_relationship';
    case UnsafeRelationship = 'unsafe_relationship';
    case TooManySheets = 'too_many_sheets';
    case RowLimitExceeded = 'row_limit_exceeded';
    case ColumnLimitExceeded = 'column_limit_exceeded';
    case CellLimitExceeded = 'cell_limit_exceeded';
    case MergeLimitExceeded = 'merge_limit_exceeded';
    case SharedStringLimitExceeded = 'shared_string_limit_exceeded';
    case TextLimitExceeded = 'text_limit_exceeded';
    case InvalidCellReference = 'invalid_cell_reference';
    case InvalidCellValue = 'invalid_cell_value';
    case FormulaNotAllowed = 'formula_not_allowed';
    case InvalidSelection = 'invalid_selection';
}
