# OIDC secrets-at-rest migration

Set the same canonical `WAASEYAA_APP_SECRET` on every issuer process before the
upgrade. Take and verify a trusted database backup, stop issuer traffic and
background work, apply migrations, and run:

```sh
bin/waaseyaa oidc:migrate-secrets --confirm
```

The command converts existing signing private keys, opaque access tokens, and
opaque refresh tokens in one transaction. It is idempotent for already-converted
rows and refuses unrecognized or inconsistent material. Resume issuer traffic
only after the command succeeds.

Runtime reads require versioned authenticated envelopes. Changing
`WAASEYAA_APP_SECRET` invalidates persisted OIDC signing-key envelopes, access
and refresh-token envelopes, and their keyed lookup values. This work package
does not provide re-encryption or rotation tooling.
