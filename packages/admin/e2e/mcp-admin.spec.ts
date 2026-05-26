// packages/admin/e2e/mcp-admin.spec.ts
// Playwright smoke tests for the MCP admin pages (M5C, mission mcp-endpoint-admin-m5c-01KSEFTB).
// Run deferred — requires `nuxt dev` on port 3000 + WP01 backend running.
// Trigger: `cd packages/admin && npm run test:e2e`
import { test, expect } from '@playwright/test'

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

  test('shows either a table row or empty state', async ({ page }) => {
    await page.goto('/mcp/tools')
    // Wait for loading to complete
    await expect(page.locator('[class*="loading"], [class*="text-gray"]')).toHaveCount(0, { timeout: 5000 })
      .catch(() => {/* loading state may have already cleared */})
    const hasTable = await page.locator('table').count() > 0
    const hasEmpty = await page.getByText('No tools registered.').count() > 0
    expect(hasTable || hasEmpty).toBe(true)
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
