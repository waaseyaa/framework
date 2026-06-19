# Spec Quality Checklist — Windows Runtime Ergonomics

## Content Quality
- [x] D1 correctly attributed to downstream constraint, not a framework scaffold bug
- [x] D4 root cause (bundled OpenSSL-less php.exe shadowing system PHP via PATH) evidenced
- [x] Removed `WAASEYAA_FRANKENPHP_BIN` knob (alpha.225) noted as the regression that left no override

## Requirement Completeness
- [x] `serve:franken` override requirement (FR-001) does not rely on PATH
- [x] Documentation correction requirement (FR-002) removes the harmful "no per-machine paths" claim
- [x] Caret-upgrade guidance (FR-003) addresses the exact-pin failure mode
- [x] Front-controller convergence explicitly out of scope / must stay green (NFR-001)

## Filing Readiness
- [x] D1 framework-vs-downstream boundary stated as a constraint (C-001)
- [x] Sibling Windows on-ramp bug (#1628) separated in Scope/Out
- [ ] Decision confirmed: env-var override vs wrapper script (A-001 — needs runtime owner sign-off)
