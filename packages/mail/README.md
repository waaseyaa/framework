# waaseyaa/mail

**Layer 0 — Foundation**

Email delivery abstraction for Waaseyaa applications.

Provides a `MailerInterface` and Twig-powered `TwigMailRenderer` for template-based emails.

Key classes: `MailerInterface`, `Envelope`, `TwigMailRenderer`, `TransportInterface` (local, array, SendGrid).

## Error handling

**Non-critical mail** (e.g. notification side effects, activity digests): wrap the `MailerInterface::send()` call in a try-catch and log the failure via `LoggerInterface` to avoid crashing the primary request. These are best-effort deliveries where a failure is recoverable.

**Auth-critical mail** (password resets, email-address verification, security alerts): let the exception propagate. Swallowing a send failure here would silently leave the user unable to regain access or verify their account. Catch only to add context or translate the exception, then re-throw.
