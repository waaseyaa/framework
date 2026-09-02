# Upgrade from alpha.299 to the next alpha

## Verified-email authentication eligibility (#2757)

`auth.require_verified_email` is now enforced across the complete framework
authentication lifecycle. Sites setting it to `true` will invalidate existing
password sessions and reject bearer identities for active Users whose
canonical `email_verified` value is false. No user-row rewrite is required;
the next request re-evaluates current state and clears an ineligible PHP
session's identity keys.

The public `AuthManager` constructor now requires the framework
`AuthenticationEligibilityInterface`, and `AuthManager::login()` throws
`AuthenticationEligibilityException` before session mutation for an
ineligible User. Applications should resolve `AuthManager` from the container
instead of constructing a parallel policy.

`POST /api/auth/resend-verification` is now public and requires an `email` JSON
field. It returns a uniform success response for absent, verified, and eligible
unverified lookups, and is independently limited by normalized email and IP.
The bundled admin client now supplies that field from either the current
account or the public verification form; custom clients must do the same.

Successful password-login and `GET /api/user/me` account documents now include
the camelCase boolean `emailVerified` field used by the admin account contract.

Custom `UserInternalFieldReaderInterface` implementations must now construct
`UserVerificationSnapshot` with the canonical active-state boolean as its third
argument. The argument is intentionally required: omitting active state at the
authentication policy boundary must fail instead of treating an unknown state
as active.

New registrations lowercase email addresses before uniqueness checks and
storage. Existing mixed-case rows are not rewritten; resend first attempts the
submitted spelling and identity lookup retains an exact legacy-email match
before case-insensitive canonical fallback. Registration uniqueness uses that
same canonical equality, so a case variant cannot create a second account.

Custom `UserIdentityLookupInterface` implementations must add
`findActiveByMail()`. Recovery controllers use this mail-only method so an
email-shaped username cannot shadow the account that owns the address.

## Bounded field-id compatibility window (#2786)

The field authority retains the historical `int`, `bool`, `uri`, `timestamp`,
`map`, and `list_string` ids as dedicated, non-blueprint plugins. Existing
definitions may continue to boot, but new blueprint definitions must use the
canonical ids. Their preserved projections are:

| id | entity/API schema | GraphQL | direct storage |
|---|---|---|---|
| `int` | string | `String` | `int` |
| `bool` | string | `String` | `boolean` |
| `uri` | string/URI | `String` | `varchar(2048)` on base-table; `text` on former ColumnSpecMap paths |
| `timestamp` | ISO date-time string | `String` | `text` (Unix values remain the domain input and are serialized to ISO) |
| `map` | string | `String` | `text` |
| `list_string` | string | `String` | `text` |

The URI distinction is intentional and is selected only by the explicit
`FieldStorageSchemaContext` seam; it is not a second type map. Migrate new
code to `integer`, `boolean`, `link`, `datetime`, `json`, or `list` as
appropriate. Legacy `int` and `bool` retain the base text Admin widget, while
`uri`, `timestamp`, and `list_string` retain URL, date-time, and select widgets
respectively. `list_string` also preserves allowed-value keys and labels in
the entity schema and Admin metadata. The compatibility ids do not enable
blueprint admission.

The following historical ids remain hard removals and must be migrated before
boot: `computed`, `number`, `list_integer`, `list_float`, `password`,
`double`, `datetime_immutable`, `slug`, and `array`. Replace them with an
explicit registered type (`float`/`decimal`, `list`, `datetime`, `json`, or a
consumer-owned field plugin), and add the equivalent `#[FieldType]` plugin if
the value semantics are not represented by a canonical type. No `uuid`,
`bigint`, or `numeric` compatibility ids are admitted.
