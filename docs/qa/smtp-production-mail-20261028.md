# SMTP production mail candidate

Change identity: `smtp-production-mail-20261028`; branch
`codex/smtp-production-20261028`, based on `9cf95e395483f8faae37f64c24cba61623098209`.
This is an unintegrated review candidate, not a release or production claim.

## Verification

- PHP 8.5.11, Symfony Mailer/Mime 8.1.10, WSL Ubuntu under `/home`.
- `php vendor/bin/phpunit --no-coverage packages/mail/tests packages/user/tests/Unit packages/auth/tests/Unit packages/notification/tests/Unit`:
  756 tests, 1,801 assertions passed. Subsequent focused mail/provider tests cover
  the final refusal of Symfony's falsey `0` username and negotiated TLS version.
- The new tests first failed on missing SMTP/configuration support. The loopback
  no-AUTH case then demonstrated that Symfony's relay-capable transport could
  submit without AUTH; the minimal authenticated-submission lifecycle guard
  makes it refuse before MAIL FROM. There is no custom SMTP or MIME parser.
- Real loopback submission tests cover LOGIN and XOAUTH2, multipart bodies,
  STARTTLS, TLS 1.2/1.3, missing TLS, missing/incompatible AUTH, rejected AUTH,
  untrusted certificates and mismatched certificate names. No real mailbox,
  credential, tenant or external message is involved.
- Provider tests prove SMTP selection despite an old SendGrid key, shared
  binding, default sender, and reset/verification/welcome dispatch through the
  actual user provider. Local and array configurations cannot enable auth mail.
- Composer policy, package layers, changelog fragments, Symfony import boundary
  and source formatting are checked. Two narrow Symfony adapter exceptions carry
  rationales and review triggers in the existing import-boundary allowlist.

## Dependency and consumer boundary

Added runtime dependencies are Symfony Mailer/Mime and their email-validator,
lexer and IDN-polyfill closure. No pre-existing external dependency version was
changed. Path-package metadata in the development lock was refreshed by
Composer. The upstream base MailServiceProvider, UserServiceProvider and
AuthMailer match Sheguiandah's installed pinned source byte for byte after
newline normalization. No vendor files or application framework refs were edited.

Composer audit still reports three pre-existing advisories on the unchanged
ExpressionLanguage, HttpFoundation and Yaml versions. None is in the new mail
dependency closure. Framework dependency maintenance owns their disposition;
this work does not claim a green repository audit or release qualification.

## Remaining gates

Self-review only. Under `docs/governance/agent-contract.md`, independent review
must precede integration of this auth/shared infrastructure change. No independent
reviewer or approval is claimed. Then use governed split publication, pin both
mail and user package refs in the consumer, and qualify that exact consumer lock.
Do not activate production SMTP against the old application lock.

Microsoft 365 authentication was already refused by tenant policy in a separate
host qualification. Do not repeat that login until its administrator enables
the approved mailbox submission method. Actual delivery and production auth-mail
readiness remain operational acceptance, separate from local transport proof.
XOAUTH2 consumes a valid token; unattended token acquisition/refresh is not
implemented or represented as accepted here.
