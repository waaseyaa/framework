# SMTP production mail

Change identity: `smtp-production-mail-20261028`. This supplies the Symfony SMTP
transport originally proposed in Framework issue 296, without adding an app-local
mail implementation.

The shared mail provider must support direct authenticated SMTP so applications
can use Microsoft 365 without a server-wide sendmail relay. Account reset,
verification and welcome mail must recognize the same effective delivery
configuration as the shared mailer. Local logging must never count as delivery.

Use maintained Symfony Mailer 8.x and Mime rather than implementing SMTP, MIME,
STARTTLS or authentication. This fits the repository's PHP 8.5 and Symfony 8
baseline. Symfony provides required TLS, peer verification, LOGIN and XOAUTH2.
Waaseyaa retains transport selection, envelope mapping and safe failure policy.
The dependency is runtime in `waaseyaa/mail`, with no framework bundle required.

`mail.transport` explicitly selects `smtp`, `sendgrid`, `array` or `local`.
When omitted, an existing nonempty SendGrid key and sender select SendGrid;
otherwise local is selected. Unknown or incomplete SMTP configuration refuses
before network access. SMTP requires `mail.from_address` and `mail.smtp` with
host, integer port, username, password, and auth mode (`login` or `xoauth2`).
The password field carries the current access token for XOAUTH2; token issuance
and refresh are outside this bounded integration. Do not select XOAUTH2 with a
mailbox password or claim unattended OAuth readiness without token management.

SMTP always requires TLS with certificate and peer-name verification. It uses
STARTTLS, a bounded socket timeout, and only the configured authenticator. It
never falls back to plaintext, another authentication method or local logging.
Transport/auth failures propagate as a sanitized delivery exception with no
SMTP transcript, credential, message body, reset token or previous exception.
Headers cannot change envelope routing, sender, subject or MIME structure.

Plan: reproduce missing SMTP/config-aware auth behavior, implement the minimal
adapter and shared configuration authority, then test envelope mapping, TLS and
auth settings, refused configuration, delivery failure, auth-mail provider wiring
and a loopback SMTP STARTTLS exchange. Real Microsoft 365 delivery remains an
operator qualification after tenant policy permits submission. No retry against
a tenant already refusing SMTP authentication is part of this change.

Independent review is required before integration because auth delivery and
shared infrastructure change. No independent reviewer is assigned or implied by
self-review. The candidate can be prepared and tested while that gate is pending.

References: <https://symfony.com/doc/8.0/mailer.html> and installed Symfony 8.1.10
transport source/tests, especially `EsmtpTransport::setRequireTls()` and
`SocketStream` peer verification. Root and package locks record exact versions.
