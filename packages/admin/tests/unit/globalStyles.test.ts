import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const read = (path: string) => readFileSync(`${process.cwd()}/${path}`, 'utf8')

describe('global admin design system', () => {
  it('loads shared controls and tokens independently of the default shell layout', () => {
    const config = read('nuxt.config.ts')
    const globalStyles = read('app/assets/admin.css')
    const shell = read('app/components/layout/AdminShell.vue')

    expect(config).toContain("css: ['~/assets/admin.css']")
    expect(globalStyles).toContain('--admin-target-size: 44px')
    expect(globalStyles).toContain('.field-input {')
    expect(globalStyles).toContain('.btn-primary {')
    expect(globalStyles).toContain('font-family:')
    expect(shell).not.toContain('<style>')

    const buttonRule = globalStyles.indexOf('.btn {')
    const sidebarCloseRule = globalStyles.indexOf('.sidebar-close {')
    const responsiveSidebarCloseRule = globalStyles.lastIndexOf('.sidebar-close {')
    expect(buttonRule).toBeGreaterThanOrEqual(0)
    expect(sidebarCloseRule).toBeGreaterThan(buttonRule)
    expect(responsiveSidebarCloseRule).toBeGreaterThan(sidebarCloseRule)
  })

  it.each([
    'entity-editor-embed/[entityType]/[id].vue',
    'page-builder-embed/[surface]/[id].vue',
  ])('keeps %s shell-free while inheriting application styles', (route) => {
    const page = read(`app/pages/${route}`)

    expect(page).toContain('definePageMeta({ layout: false })')
    expect(page).not.toContain('LayoutAdminShell')
  })
})
