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
checkpoints remain verifiable under the legacy hash-chain rules until the first
new `hmac-sha256.hkdf-v1:` checkpoint is written. From that checkpoint onward,
`audit:verify` requires and verifies every versioned signature and reports
`checkpoint_signature` on a missing, malformed, downgraded, or incorrect value.

Local, `dev`, `development`, and `testing` kernels may boot without the variable;
each kernel instance then gets a random ephemeral master secret. Persisted
signatures or encrypted data will not survive a kernel restart in that mode, so
set a stable application secret for any durable local workflow.
