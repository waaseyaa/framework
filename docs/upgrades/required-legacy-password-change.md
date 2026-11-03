# Imported-password first login

Consumers enable `auth.legacy_passwords.require_change: true` alongside explicit
`formats: [phpass, wordpress_bcrypt]`. Preserve imported hashes in protected
`legacy_pass`, leave `pass` empty and preserve any already-established native
credential. Never expose or log imported hashes.

Canonical LoginController returns `data.attributes.state` as
`password_change_required` and a short-lived `data.attributes.token`. HTML
adapters send the member to the existing reset form. This is not an authenticated
or pending-two-factor identity. Display forgot-password email as fallback.
Reset may return `different_password_required`; show a request to choose a
different password. After success, sign in with the new password normally.

ResetPasswordController now requires the canonical LegacyPasswordChange service
as its third constructor argument. The former nontransactional direct path is
removed. Provider construction supplies it automatically. All resets refuse
inactive accounts, atomically consume one token and persist the new credential,
clear imported credentials, revoke sibling reset tokens and advance session
generation. SQL repository and token store must share the managed connection.

This requires entity-storage's `sharesTransactionWith()` capability. Qualify the
exact auth/routing/entity-storage package cohort in each consuming application.
MCP durable bearer now also requires canonical authentication eligibility in its
constructor and provider binding. Include the reviewed mcp split package in
consumer adoption so an existing token cannot bypass required password setup.

See [the contract](../specs/legacy-password-change.md).
