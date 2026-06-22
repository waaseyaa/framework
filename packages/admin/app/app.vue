<template>
  <!--
    Immediate, route-level navigation feedback. Shows the instant a route change
    starts (e.g. clicking a list "Edit" link), so the UI is never silent while
    the destination page resolves. Brand teal to match the admin palette.
  -->
  <NuxtLoadingIndicator color="#0f766e" />
  <!--
    Flagship Wayfinding overlay (mission wayfinding-01KVGH5X, Phase 3). A global,
    persistent sibling of the layout so element-anchored beacons of a live trail
    render over any page. Invisible until a beacon arrives on this session's SSE
    channel; fully keyboard-navigable and accessible.
  -->
  <WayfindingOverlay />
  <NuxtLayout>
    <NuxtPage />
  </NuxtLayout>
</template>
