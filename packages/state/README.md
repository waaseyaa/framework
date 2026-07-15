# waaseyaa/state

SQL-backed and in-memory application state.

`SqlState` requires the 32-byte key derived with
`waaseyaa.state.payload-hmac.v1`. It stores serialized values only inside a
strict versioned HMAC-SHA-256 envelope and verifies that envelope before every
deserialization. `MemoryState` is unchanged.

Existing SQL state values are invalidated at cutover: stop application workers,
clear the `state` table, deploy the keyed reader/writer, and allow application
state to rebuild. `SqlState` does not accept unsigned rows.

Changing `WAASEYAA_APP_SECRET` invalidates all persisted SQL state values.
