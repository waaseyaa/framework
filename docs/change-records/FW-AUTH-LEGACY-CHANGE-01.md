# FW-AUTH-LEGACY-CHANGE-01

Parent: `396121891503b72f099faa027c3d7b2795742a02`.

Consumers may opt into requiring a different password after proving an imported
credential. Default legacy upgrade behavior remains compatible. Framework owns
verification, the limited challenge, every authentication gate and atomic reset.
Site consumers own presentation and explicitly mapping imported hash fields.

Plan: add bounded WordPress bcrypt verification beside phpass; opt-in legacy
change policy; canonical login issues a five-minute password-reset capability
bound to credentials/generation without a session; canonical reset consumes it
and updates credentials together; deny pending identities across all login,
session, bearer and 2FA stages. Email recovery remains available and must also
choose a different password. Test both formats, policy parity, replay, expiry,
disabled accounts, rollback and existing native-password behavior. Independent
authentication review is required before integration and consumer deployment.

No DNS changes, email sends, vendor patches or wholesale production reimport.
Verification and exact reviewed candidate will be recorded before integration.

Review correction: the consumer does install MCP. The earlier absence assumption
was false. Extend the bounded package cohort to mcp and enforce canonical
eligibility for durable bearer owners before declaring the consumer ready.
The HTTP candidate remains independently reviewed; the MCP follow-up needs its
own focused tests and independent review before publication/adoption.
