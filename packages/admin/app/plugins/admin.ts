import { BootstrapAuthAdapter } from '../adapters/BootstrapAuthAdapter'
import { JsonApiTransportAdapter } from '../adapters/JsonApiTransportAdapter'
import { ADMIN_CONTRACT_VERSION } from '../contracts/version'
import type { AdminBootstrap } from '../contracts/bootstrap'
import type { AdminRuntime } from '../contracts/runtime'

export default defineNuxtPlugin(async (): Promise<{ provide: { admin: AdminRuntime | null } }> => {
  const config = useRuntimeConfig()
  const baseUrl = (config.public.baseUrl as string) || ''

  // Step 1: Resolve bootstrap — inline first, endpoint fallback
  let bootstrap: AdminBootstrap

  if (import.meta.client && window.__WAASEYAA_ADMIN__) {
    bootstrap = window.__WAASEYAA_ADMIN__
  } else {
    const response = await $fetch<AdminBootstrap>(`${baseUrl}/admin/bootstrap`, {
      ignoreResponseError: true,
      onResponseError({ response: res }) {
        if (res.status === 401) {
          // Will be handled below after version check attempt
        }
      },
    }).catch(() => null)

    if (!response) {
      // No bootstrap available — redirect to a default login or show error
      if (import.meta.client) {
        window.location.href = `${baseUrl}/login`
      }
      // Return a stub to prevent plugin crash during SSR
      return { provide: { admin: null } }
    }
    bootstrap = response
  }

  // Step 2: Validate contract version
  if (bootstrap.version !== ADMIN_CONTRACT_VERSION) {
    throw createError({
      statusCode: 500,
      message: `Admin contract version mismatch: expected ${ADMIN_CONTRACT_VERSION}, got ${bootstrap.version}`,
      fatal: true,
    })
  }

  // Step 3: Instantiate adapters
  const auth = new BootstrapAuthAdapter(bootstrap)
  const apiPath = bootstrap.transport.apiPath ?? '/api'
  const resolvedApiPath = `${baseUrl}${apiPath}`
  const transport = new JsonApiTransportAdapter(resolvedApiPath, bootstrap.tenant)

  // Step 4: Build runtime
  const runtime: AdminRuntime = {
    bootstrap,
    auth,
    transport,
    catalog: bootstrap.entities,
    tenant: bootstrap.tenant,
  }

  return { provide: { admin: runtime } }
})
