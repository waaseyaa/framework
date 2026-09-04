<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation\Exception;

/**
 * The reserved `GEN0xx` refusal ids for the generation execution and plan
 * boundary (ADR-025 D-5).
 *
 * This family is deliberately separate from `SITE0xx`, which codes
 * manifest and blueprint *content* errors. These are refusals about where and
 * how bytes may land, not about the shape of an authored document, and
 * conflating the two would make a `SITE0xx` reader responsible for a boundary
 * it does not own.
 *
 * The set is closed by the decision, not by this file: ADR-025 D-5 states that
 * any additional id is an amendment to that decision rather than a silent
 * addition. Backing strings are stable surface -- a plan reader switches on
 * them, and they are what an operator quotes.
 *
 * Unlike `SITE0xx`, which is a set of string literals inlined at each throw
 * site with no registry, this family is enumerated from the moment it ships.
 * That is a deliberate improvement rather than an accidental divergence: the
 * older family has already collided with itself, using `SITE010` and `SITE011`
 * for two different meanings in two different namespaces, because nothing
 * enumerated either set.
 *
 * @api
 */
enum GenerationErrorCode: string
{
    /** Traversal, absolute path, backslash, embedded null. */
    case UnsafePath = 'GEN001_UNSAFE_PATH';

    /** A path component or target resolves through a symlink. */
    case SymlinkRejected = 'GEN002_SYMLINK_REJECTED';

    /** An existing unrecognized file or directory blocks the target, or the target is recorded to a different generation unit. */
    case CollisionRefused = 'GEN003_COLLISION_REFUSED';

    /** A managed-region digest drifted, so a regeneration cannot tell an edit from a substitution. */
    case AmbiguousExtensionRegion = 'GEN004_AMBIGUOUS_EXTENSION_REGION';

    /** The plan digest or the captured project-state digest no longer matches what apply recomputes. */
    case StalePlan = 'GEN005_STALE_PLAN';

    /** A user-supplied name fails the make-handler identifier grammar, or a unit id fails the D-2.1 grammar. */
    case MaliciousIdentifier = 'GEN006_MALICIOUS_IDENTIFIER';

    /** An unsupported field type or generator-feature token at the plan-compilation boundary. */
    case UnsupportedDeclaration = 'GEN007_UNSUPPORTED_DECLARATION';

    /** A concurrent initialization holds the project lock. */
    case Locked = 'GEN008_LOCKED';

    /** A recorded row disappeared from a supplied unit's output with no declared retirement. */
    case UndeclaredUnitRetirement = 'GEN009_UNDECLARED_UNIT_RETIREMENT';

    /** A duplicate unit id, a row naming an unknown unit, or one path claimed by two units. */
    case UnitPathConflict = 'GEN010_UNIT_PATH_CONFLICT';

    /** A frozen unit renders paths its recorded set does not contain, or an evolving unit drops a recorded path. */
    case UnauthorizedSetDelta = 'GEN011_UNAUTHORIZED_SET_DELTA';

    /** Ownership: a registration is claimed by another unit, already present unowned, duplicated, or attributed to an unknown unit. */
    case RegistrationOwnershipConflict = 'GEN012_REGISTRATION_OWNERSHIP_CONFLICT';

    /** Declaration evolution: a plan changes a seeded unit's registration set after creation. */
    case SeededRegistrationRedeclared = 'GEN013_SEEDED_REGISTRATION_REDECLARED';

    /** Existing Composer state: the declared provider list is absent-shaped, malformed, or duplicated. Refused at read. */
    case InvalidComposerProviderState = 'GEN014_INVALID_COMPOSER_PROVIDER_STATE';

    /** Persisted roster shape and order: a malformed row, an unknown member, an empty string, or a roster out of canonical order. Refused at read. */
    case InvalidRegistrationRoster = 'GEN015_INVALID_REGISTRATION_ROSTER';
}
