# Upgrade Note: Legacy Password Verification and One-Time Upgrade

Issue #2544. A migration from another system arrives with credentials Waaseyaa
cannot verify, and the alternative — forcing every migrated member through a
password reset — is not a minor inconvenience for a community roster. It is a
hard cutover that loses the people who never complete it.

Waaseyaa can now accept a supported foreign credential **exactly once**, and
that acceptance is what destroys it.

**This changes nothing for a deployment that migrated nothing.** Legacy
verification is opt-in and the default chain is empty.

## Supported formats

| Format | Prefix | Status |
|---|---|---|
| phpass portable | `$P$`, `$H$` | **Supported.** Every account WordPress created before 6.8. |
| Modern WordPress | `$wp$2y$` | **Not supported.** See below. |

### Why `$wp$2y$` is not supported

WordPress 6.8 wraps bcrypt around a pre-hash of the password. Verification is
only correct if that pre-hash is reproduced byte for byte — the digest
algorithm, the key, and the encoding all have to match, and a near-miss does not
fail loudly: it rejects every password while looking like a working
implementation.

This change does not implement it, because it could only be written from
recollection and there is no authoritative reference to verify it against here.
Shipping a guessed construction into an authentication path would be worse than
shipping nothing: it would lock out exactly the members the feature exists to
carry across, and the symptom would be indistinguishable from "they forgot their
password."

`$wp$2y$` is refused as an unsupported format, like any other unrecognized
value. Adding it later is a new class implementing
`LegacyPasswordVerifierInterface` plus one `match` arm — no contract change.

## Enabling it

```php
// config/waaseyaa.php
'auth' => [
    'legacy_passwords' => [
        'formats' => ['phpass'],
    ],
],
```

An unknown format name raises `InvalidArgumentException` at boot rather than
being skipped. A typo that degraded to "verifies nothing" would lock out every
migrated member with no signal at all.

## The storage contract

An imported credential goes in **`User::$legacy_pass`**, and `pass` is left
empty.

```php
$user->setLegacyPassword($wordpressHash); // '$P$B...'
// Do NOT also set a password: pass stays ''.
```

Keeping the two apart is what makes "a current hash is never downgraded" a
structural property rather than a rule someone has to remember. `pass` only ever
holds a current Waaseyaa hash, only `legacy_pass` is ever offered to a legacy
verifier, and an account that has a current hash never consults its legacy value
at all — so residue from a partially-applied migration cannot pull an upgraded
account back onto the weaker credential.

`legacy_pass` is `FieldReadLevel::Internal`, is on every read surface's
always-internal list alongside `pass`, and is readable only through the audited
`user.credentials` capability. It is also Forbidden on the generic field
surface (`UserAccessPolicy::CREDENTIAL_FIELDS`), so a JSON:API PATCH or agent
field write cannot plant or replace it. It never appears in entity or API
serialization, GraphQL, SSR, the admin surface, agent tools, revision restore,
logs, or audit payloads.

## What happens at login

1. The account must be active. A disabled account never reaches a verifier.
2. `pass` is verified natively. If it is set, that is the whole decision.
3. Only if `pass` is empty is `legacy_pass` offered to the configured chain.
4. On acceptance, one `save()` writes a current hash to `pass` **and** clears
   `legacy_pass`. There is no window in which the account has neither.

A failed rewrite does not fail the login — the credential was valid, and the
rewrite is bookkeeping. The account is left exactly as it was, so the next login
retries. The failure is logged as `auth.legacy_password_upgrade_failed` with the
account id and the exception **class** only; the credential, the password, and
the exception message are never logged, because the first two are password
equivalents and the third can quote them.

Concurrent logins both succeed and both write. Neither can restore the legacy
value: the value written for that field is a literal `null` on every path and is
never a copy of what was read.

## Operational rollout

**Verify one real hash before cutover.** The phpass implementation is
transcribed from Openwall's published algorithm and cross-checked in tests
against an independent transcription of the *hashing* side, but no third-party
test vector was used. Before pointing a real roster at it, take one account
whose password is known and confirm it authenticates. The specific thing this
catches is the iteration-character offset: phpass stores `log2 + 5`, so a real
WordPress hash carries `B` and performs `2 ** 13` rounds, not `2 ** 8` — an
implementation that misses that offset is self-consistent and rejects every real
hash.

Suggested sequence:

1. Import with `legacy_pass` set and `pass` empty. Do not mark accounts for
   reset.
2. Enable `auth.legacy_passwords.formats = ['phpass']`.
3. Verify one known account.
4. Announce normally. Members sign in with the password they already have, and
   each login quietly upgrades one account.

## Removing it later

Legacy verification is a ramp, not a permanent surface. Once the tail of
un-upgraded accounts is small enough, drop `auth.legacy_passwords.formats` back
to `[]`. Accounts still holding a `legacy_pass` then fall to the ordinary
password-reset flow, which is where they were headed anyway — the point of this
feature is to make that set small, not to keep MD5-based verification alive.

The iteration ceiling is bounded for the same reason the format list is: the
stored cost parameter comes out of a system Waaseyaa does not control.
`PhpassPasswordVerifier` refuses a count above `2 ** 17` rather than performing
it, so an accidental or hostile value cannot turn a login into a denial of
service.
