import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockEntityTypesRoute } from './fixtures/routes'

const fixture = fileURLToPath(new URL('./fixtures/migrated-node-schema.php', import.meta.url))
type SurfaceSchemaResponse = { data?: { properties?: Record<string, unknown> } }

test('migrated post create form omits WordPress status from its fetched schema', async ({ page }) => {
  await mockAdminBootstrapRoutes(page)
  await mockEntityTypesRoute(page)

  let postSchema: SurfaceSchemaResponse | null = null
  await page.route('**/_surface/node/action/schema', async (route) => {
    const body = route.request().postDataJSON()
    const response = JSON.parse(execFileSync('php', [fixture], { encoding: 'utf8' }))
    if (body?.bundle === 'post') postSchema = response
    await route.fulfill({ json: response })
  })

  await page.goto('/node/create')
  await page.getByLabel('Bundle').selectOption('post')

  await expect.poll(() => postSchema).not.toBeNull()
  expect(postSchema?.data?.properties).not.toHaveProperty('source_status')
  await expect(page.getByLabel('WordPress status')).toHaveCount(0)
})
