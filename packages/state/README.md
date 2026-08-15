# waaseyaa/state

SQL-backed and in-memory application state.

`SqlState` requires a caller-owned 32-byte HMAC key. It stores serialized values
only inside a strict versioned HMAC-SHA-256 envelope and verifies that envelope
before every deserialization. `MemoryState` is unchanged. The state package
does not register a built-in application-master purpose because no production
provider composes `SqlState`; an application that persists it must own and
document that key's lifecycle explicitly.

Existing SQL state values are invalidated at cutover: stop application workers,
clear the `state` table, deploy the keyed reader/writer, and allow application
state to rebuild. `SqlState` does not accept unsigned rows.

Changing the caller-owned HMAC key invalidates all persisted SQL state values.
