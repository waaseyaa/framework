# waaseyaa/telescope

**Layer 6 — Interfaces**

Reserved package name; no runtime implementation is shipped.

The previous Telescope implementation was deleted in R19 (#1992). It recorded request/query/cache data into an in-process or raw-PDO store with no production reader, while its CLI and agent-context UI were never connected to that store. The package name remains reserved so the monorepo split does not silently transfer it to another implementation.
