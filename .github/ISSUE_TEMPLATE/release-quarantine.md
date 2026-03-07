---
name: Release Quarantine — Unauthorized v1.0 Tag
about: Track and resolve an unauthorized v1.0 tag. Auto-opened by CI when UNAUTHORIZED_V1_TAG is detected.
labels: versioning, release-quarantine, p0
---

> **This issue was opened automatically by CI or manually by a team member.**
> An unauthorized `v1.0` tag has been detected. See `VERSIONING.md` for the quarantine process.

## Tag Details

- **Tag name:** <!-- e.g. v1.0.0 -->
- **Created by:** <!-- GitHub username -->
- **Created at:** <!-- datetime -->
- **On commit:** <!-- SHA -->
- **Repository:** <!-- monorepo or split package repo -->

## Quarantine Checklist

- [ ] Tag details documented above
- [ ] @jonesrussell notified (comment tagging him below)
- [ ] Russell reviewed and confirmed disposition in writing (comment or PR approval)

## Russell's Decision

<!-- To be filled in by Russell -->

- [ ] **Keep tag** — tag was authorized; proceed with release approval workflow
- [ ] **Delete tag** — tag was created in error

## Deletion Record (if applicable)

- Deleted by: <!-- username -->
- Deleted at: <!-- datetime -->
- Deletion authorized in: <!-- link to Russell's comment/PR -->
- `VERSIONING.md` audit log updated: <!-- yes/no -->

## Diagnostic Info

```
Error code: TAG_QUARANTINE_DETECTED
Tag: <tag-name>
Creator: <username>
Commit: <sha>
Required action: Owner review per VERSIONING.md Section 2
```

## References

- [VERSIONING.md — Section 2: Tagged v1.0 Handling](../../VERSIONING.md)
- [Release Approval template](.github/ISSUE_TEMPLATE/release-approval.md)
