# FW-RESTORE-BUNDLE-01

Owner: entity-storage. Framework issue #3249 follows the #2464 restore contract
and the #1968 promotion repair. Parent: 4852aa9353c1b113197a029bb1e935cfa61ee5fe.

On that parent, in-memory SQLite retained revision 1 (BODY_ONE) and revision 2
(BODY_TWO). Ordinary rollback of revision 1 created revision 3 and advanced
the base pointer to 3, yet returned and reloaded BODY_TWO. The published
pointer stayed null. With default-revision discipline the base stayed at 2,
the tip advanced to 3 and the working copy correctly returned BODY_ONE.

Use the existing bundle gateway whenever a revision snapshot becomes current.
Share the physical base and bundle write with ordinary saves and promotion.
Keep the mutation fence, pointer decisions and transaction at their existing
owners. Check the successor, later history, omitted fields, raw subtable
and fresh working copy. Exercise a populated published pointer under
discipline, plus the separate current-revision switch. Run the affected
storage, workflow and admin-surface tests and independent persistence review.

Package documentation reconciliation (#3213) and broader responsibility
extraction (#3009) retain their existing audit owners.
