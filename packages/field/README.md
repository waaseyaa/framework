# waaseyaa/field

**Layer 1 — Core Data**

Field definition and typed-data integration for Waaseyaa entities.

Defines field types, field definitions attached to `EntityType`, and the bridge between entity values and the `typed-data` validation layer. Field definitions drive JSON Schema generation in `SchemaPresenter` and formatter selection in SSR rendering.

Key classes: `FieldDefinitionInterface`, `FieldTypeManager`.
