# waaseyaa/database-legacy

**Layer 0 — Foundation**

PDO-based database abstraction for Waaseyaa applications.

Provides `PdoDatabase` with a fluent query builder (`select()`, `insert()`, `update()`, `delete()`), LIKE-safe escaping, and `FETCH_ASSOC` mode to avoid duplicate numeric-indexed columns. `PdoDatabase::createSqlite()` creates an in-memory SQLite instance for tests. `DatabaseInterface` intentionally omits `getPdo()`; use `PdoDatabase` directly when raw PDO access is needed.

Key classes: `PdoDatabase`, `DatabaseInterface`, `PdoSelect`.
