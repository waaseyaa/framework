import type { AdminRuntime } from '../contracts/runtime'

const ADMIN_RUNTIME_UNAVAILABLE_MESSAGE =
  'Admin runtime is unavailable. Ensure the admin plugin has bootstrapped before calling admin composables.'

export function requireAdminRuntime(nuxtApp: { $admin?: AdminRuntime | null } = useNuxtApp()): AdminRuntime {
  const runtime = nuxtApp.$admin

  if (!runtime) {
    throw new Error(ADMIN_RUNTIME_UNAVAILABLE_MESSAGE)
  }

  return runtime
}

export { ADMIN_RUNTIME_UNAVAILABLE_MESSAGE }
