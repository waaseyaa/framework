// tests/vue-shim.d.ts
// Vue component type shim for tsc --noEmit in test files.
// The Nuxt environment resolves .vue imports at runtime; this shim
// satisfies TypeScript's static analysis.
declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>
  export default component
}
