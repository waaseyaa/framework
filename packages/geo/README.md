# waaseyaa/geo

**Layer 0 — Foundation**

Geospatial utilities for Waaseyaa: great-circle distance calculation.

`GeoDistance::haversine($lat1, $lon1, $lat2, $lon2)` returns the great-circle distance between two lat/lon points **in kilometres**, using the Haversine formula. That is currently the package's only utility — there is no equirectangular variant, no metres option, and no coordinate parsers (despite earlier docs). Pure utility — no storage, no service, no global state.

Key classes: `GeoDistance`, `GeoServiceProvider`.
