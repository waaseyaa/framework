import type { Page } from '@playwright/test'

const DEV_ADMIN_ID = String(Number.MAX_SAFE_INTEGER)

export async function mockUnauthenticatedSession(page: Page) {
  // Match both proxy path (/_surface/) and direct path (/admin/surface/)
  await page.route(/\/(admin\/surface|_surface)\/session/, (route) =>
    route.fulfill({
      status: 200,
      json: { ok: false, error: { status: 401, title: 'Unauthorized' } },
    }),
  )
}

export async function mockLoginSuccess(page: Page, username = 'dev-admin', password = 'password') {
  await page.route('**/api/auth/login', async (route) => {
    const body = route.request().postDataJSON()
    if (body?.username === username && body?.password === password) {
      await route.fulfill({
        json: {
          jsonapi: { version: '1.1' },
          data: { id: DEV_ADMIN_ID, name: username, email: `${username}@example.com`, roles: ['admin'] },
        },
      })
    } else {
      await route.fulfill({
        status: 401,
        json: {
          jsonapi: { version: '1.1' },
          errors: [{ status: '401', title: 'Unauthorized', detail: 'Invalid credentials.' }],
        },
      })
    }
  })
}

export async function mockLoginRateLimited(page: Page) {
  await page.route('**/api/auth/login', (route) =>
    route.fulfill({
      status: 429,
      headers: { 'Retry-After': '60' },
      json: {
        jsonapi: { version: '1.1' },
        errors: [{ status: '429', title: 'Too Many Requests', detail: 'Too many login attempts. Please try again later.' }],
      },
    }),
  )
}

export async function mockLogout(page: Page) {
  await page.route('**/api/auth/logout', (route) =>
    route.fulfill({
      json: { jsonapi: { version: '1.1' }, meta: { message: 'Logged out.' } },
    }),
  )
}
