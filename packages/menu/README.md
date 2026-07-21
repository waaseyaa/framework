# waaseyaa/menu

**Layer 2 — Content Types**

Menu and menu link entity types for Waaseyaa applications.

Defines the `menu` and `menu_link` entity types for site navigation. Object-backed menu links store their optional destination independently from the rendered title in the public `target_entity_type` and `target_entity_id` fields; custom links continue to use `url`, and `target` remains the browser browsing-context target. `MenuAccessPolicy` (auto-discovered via `#[PolicyAttribute]`) allows authenticated users to view menus and requires `administer menu` permission for create/edit operations.

Key classes: `Menu`, `MenuLink`, `MenuAccessPolicy`, `MenuServiceProvider`.
