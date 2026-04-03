# waaseyaa/mail

**Layer 0 — Foundation**

Email delivery abstraction for Waaseyaa applications.

Provides a `MailerInterface` and Twig-powered `TwigMailRenderer` for template-based emails. Best-effort side effects (e.g. notification listeners) should wrap mailer calls in try-catch and log failures via `LoggerInterface` to avoid crashing the primary request.

Key classes: `MailerInterface`, `TwigMailRenderer`, `MailMessage`.
