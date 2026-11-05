# FW-S1-LOCK-REFRESH-01

The root Composer lock changed in f2c7332f8 when the auth package metadata
changed. Both S1 installed-artifact proofs rejected the old lock digest on
4852aa935, the parent of the revision restore fix (#3250). The preceding
refresh was part of the completed dependency-maintenance batch (#3237).

For #3251, install from the current lock and regenerate the evidence using
`bash tests/PackagedForm/check-s1-sqlite-artifact --write-dependency-authority`.
Accept a lock-only refresh only if the generated rows for doctrine/dbal,
doctrine/deprecations, psr/cache and psr/log retain every version, source
reference, distribution reference and installed byte digest. Run both the
SQLite topology and schema authority installed-artifact checks, obtain
independent review and qualify the restore PR against the corrected base.
