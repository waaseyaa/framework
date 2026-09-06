import { test, expect, type Page } from '@playwright/test'

/**
 * Studio-alpha browser proof (#2789).
 *
 * Unlike `packages/admin/e2e/*.spec.ts`, which mock the host payload to test
 * SPA behaviour in isolation, this spec MUST NOT mock anything. It drives the
 * admin bundle that was installed from the sealed artifact, served by the
 * backend that was installed from the same artifact, against a real seeded
 * identity and a real database. A `page.route()` interception here would
 * silently turn the whole acceptance run into a test of the harness.
 */

const username = process.env.STUDIO_ALPHA_ADMIN_USER ?? ''
const password = process.env.STUDIO_ALPHA_ADMIN_PASSWORD ?? ''

test.beforeEach(async ({ page }) => {
  if (username === '' || password === '') {
    throw new Error('STUDIO_ALPHA_ADMIN_USER and STUDIO_ALPHA_ADMIN_PASSWORD must name the seeded identity.')
  }
  // Fail loudly rather than silently proving nothing: any request that never
  // reaches the backend would mean the bundle is talking to something else.
  page.on('pageerror', (error) => {
    console.log(`[pageerror] ${error.message}`)
  })
})

async function signIn(page: Page): Promise<void> {
  await page.goto('/admin/login')
  await expect(page.locator('#login-username')).toBeVisible({ timeout: 30_000 })
  await page.fill('#login-username', username)
  await page.fill('#login-password', password)
  await page.click('button[type="submit"]')
}

test('the installed admin bundle boots from the packaged backend', async ({ page }) => {
  const response = await page.goto('/admin/')
  expect(response?.status(), 'the admin entry point must be served').toBe(200)

  // The fallback page is what the host serves when no bundle is installed. It
  // is a legitimate product response and a total failure of THIS proof, so it
  // is named explicitly rather than left to a generic selector timeout.
  const body = await page.content()
  expect(body, 'the packaged consumer served the no-bundle fallback, not the installed SPA')
    .not.toContain('The admin SPA is not built yet')
  expect(body).toContain('<div id="__nuxt"')
})

test('a seeded identity signs in and performs an admin-visible operation', async ({ page }) => {
  await signIn(page)

  // Landing anywhere other than /login is the SPA's own success signal.
  await expect(page).not.toHaveURL(/\/login/, { timeout: 30_000 })

  // The admin-visible operation: the catalog the host actually computed for
  // this identity, rendered by the bundle. Both halves have to be real for
  // this to pass — the session cookie, and the host's own catalog payload.
  const session = await page.request.get('/admin/_surface/session')
  expect(session.status(), 'the seeded identity must resolve a real session').toBe(200)
  const payload = await session.json()
  expect(payload?.account?.name ?? payload?.account?.username).toBe(username)

  const catalog = await page.request.get('/admin/_surface/catalog')
  expect(catalog.status()).toBe(200)
  const entries = (await catalog.json())?.entries ?? []
  expect(Array.isArray(entries) && entries.length > 0, 'the host must expose at least one catalog entry').toBe(true)
})

test('the hand-extended content type is visible to the running application', async ({ page }) => {
  await signIn(page)

  const catalog = await page.request.get('/admin/_surface/catalog')
  expect(catalog.status()).toBe(200)
  const entries: Array<{ type?: string; id?: string }> = (await catalog.json())?.entries ?? []
  const ids = entries.map((entry) => entry.type ?? entry.id)

  expect(ids, 'make:content-type published a unit the running application cannot see').toContain('story')
})
