# Application secret and HKDF migration

Waaseyaa now has one application-level key-custody input:
`WAASEYAA_APP_SECRET`. Staging, production, and any unknown environment refuse
to boot unless it has the exact form `base64:<canonical RFC 4648 base64>` and
decodes to exactly 32 bytes. Generate it once per installation, store it only in
the deployment secret store or environment file, and never commit it:

```sh
printf 'WAASEYAA_APP_SECRET=base64:%s\n' "$(openssl rand -base64 32)"
```

Existing installations must set the variable before upgrading. The kernel
derives independent keys with HKDF-SHA-256 and versioned purpose labels; old
`audit.checkpoint_hmac_key` and `cache.hmac_key` configuration is ignored and
should be removed.

Existing cache rows were signed with a different key or were unsigned, so they
become ordinary cold misses and self-heal when rewritten. Existing audit
checkpoints must be authenticated explicitly before keyed `audit:verify` will
pass. First take and independently verify a trusted backup, place the application
in maintenance mode so checkpoint writers are stopped, then run:

```sh
bin/waaseyaa audit:migrate-checkpoint-signatures --confirm
bin/waaseyaa audit:verify
```

The migration transaction first verifies the complete legacy hash chain,
refuses malformed, mixed, changed, or broken history, signs every checkpoint
(including genesis), and strict-verifies the result before commit. This step
establishes authenticity from the operator-chosen migration point forward; it
cannot prove that a database was not altered before that trusted point. Fresh
kernel-created schemas sign genesis immediately. Keyed verification rejects any
missing, bare, malformed, downgraded, or incorrect checkpoint signature.

Local, `dev`, `development`, and `testing` kernels may boot without the variable;
each kernel instance then gets a random ephemeral master secret. Persisted
signatures or encrypted data will not survive a kernel restart in that mode, so
set a stable application secret for any durable local workflow.

## Rotation impact

Changing `WAASEYAA_APP_SECRET` invalidates all material bound to its derived
keys:

- cache payload signatures;
- audit checkpoint HMAC validation;
- OIDC private-key and token ciphertext, plus opaque-token lookup values;
- pending and failed persistent queue payloads;
- auth reset, verification, and invite token HMAC validation when those tokens
  are derived from the application master rather than an independent
  `AUTH_TOKEN_SECRET`;
- SQL state payloads.

This batch does not provide re-encryption or key-rotation tooling. Plan rotation
as a maintenance event: preserve required audit history under the existing key,
replace OIDC material and sessions, drain or clear persistent queues, clear SQL
state and caches, then start every process with the new secret.
