// packages/admin/e2e/mcp-admin.spec.ts
// Playwright smoke tests for the MCP admin pages (M5C, mission mcp-endpoint-admin-m5c-01KSEFTB).
// Run deferred — requires `nuxt dev` on port 3000.
// Trigger: `cd packages/admin && npm run test:e2e`
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes } from './fixtures/routes'

// Like every other protected-page spec, the admin shell redirects to /login
// unless the bootstrap/session routes are mocked. The MCP data endpoints are
// stubbed with empty payloads so the registry empty-state and the server-config
// transport/protocol banners render deterministically without a live backend.
test.beforeEach(async ({ page }) => {
  await mockAdminBootstrapRoutes(page, { mcp: true })

  await page.route('**/api/mcp/tools', (route) =>
    route.fulfill({ json: { data: { rows: [] } } }),
  )

  await page.route('**/api/mcp/server-config', (route) =>
    route.fulfill({
      json: {
        data: {
          config: {
            transport: 'streamable-http',
            protocolVersion: '2025-06-18',
            registeredClients: [],
            serverCapabilities: [],
          },
        },
      },
    }),
  )
})

test.describe('MCP admin nav', () => {
  test('MCP nav section is visible in the sidebar', async ({ page }) => {
    await page.goto('/')
    await expect(page.getByTestId('nav-section-mcp')).toBeVisible()
    await expect(page.getByTestId('nav-mcp-tools')).toBeVisible()
    await expect(page.getByTestId('nav-mcp-server-config')).toBeVisible()
  })
})

test.describe('MCP tool registry page', () => {
  test('renders the tool registry title', async ({ page }) => {
    await page.goto('/mcp/tools')
    await expect(page.getByRole('heading', { level: 1 })).toContainText('MCP Tool Registry')
  })

  test('shows the empty state when no tools are registered', async ({ page }) => {
    // beforeEach already stubs /api/mcp/tools with an empty row set.
    await page.goto('/mcp/tools')
    // Anchor on the heading first so we know the page component mounted (Nuxt
    // dev compiles route chunks lazily and can be slow on first hit — see #1419).
    await expect(page.getByRole('heading', { level: 1 })).toContainText('MCP Tool Registry')
    await expect(page.getByText('No tools registered.')).toBeVisible({ timeout: 15000 })
  })

  test('renders a table row when tools are registered', async ({ page }) => {
    // Test-level route overrides the empty beforeEach stub (Playwright handlers
    // run in reverse registration order, so this one wins).
    await page.route('**/api/mcp/tools', (route) =>
      route.fulfill({
        json: {
          data: {
            rows: [
              {
                name: 'create_node',
                summary: 'Create a content node',
                category: 'content',
                requiredCapabilities: ['node.create'],
              },
            ],
          },
        },
      }),
    )

    await page.goto('/mcp/tools')
    await expect(page.getByRole('heading', { level: 1 })).toContainText('MCP Tool Registry')
    // The row is rendered client-side after fetchTools() resolves. A single
    // auto-retrying assertion rides through Nuxt dev's SSR→hydration window
    // (and any first-hit recompile) without a gap between separate waits.
    await expect(page.getByRole('link', { name: 'create_node' })).toBeVisible({ timeout: 15000 })
  })
})

test.describe('MCP server config page', () => {
  test('renders the server config title', async ({ page }) => {
    await page.goto('/mcp/server-config')
    await expect(page.getByRole('heading', { level: 1 })).toContainText('MCP Server Config')
  })

  test('shows transport and protocol version banners', async ({ page }) => {
    await page.goto('/mcp/server-config')
    await expect(page.getByText('Transport')).toBeVisible()
    await expect(page.getByText('Protocol Version')).toBeVisible()
  })
})
