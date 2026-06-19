<template>
  <!--
    Immediate, route-level navigation feedback. Shows the instant a route change
    starts (e.g. clicking a list "Edit" link), so the UI is never silent while
    the destination page resolves. Brand teal to match the admin palette.
  -->
  <NuxtLoadingIndicator color="#0f766e" />
  <NuxtLayout>
    <NuxtPage />
  </NuxtLayout>
</template>
