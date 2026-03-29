import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import LoginForm from '~/components/auth/LoginForm.vue'

describe('LoginForm', () => {
  it('renders username and password fields', async () => {
    const wrapper = await mountSuspended(LoginForm)
    expect(wrapper.find('#login-username').exists()).toBe(true)
    expect(wrapper.find('#login-password').exists()).toBe(true)
  })

  it('renders labels for accessibility', async () => {
    const wrapper = await mountSuspended(LoginForm)
    const labels = wrapper.findAll('label')
    expect(labels.length).toBeGreaterThanOrEqual(2)
  })

  it('emits submit with username and password', async () => {
    const wrapper = await mountSuspended(LoginForm)
    await wrapper.find('#login-username').setValue('admin')
    await wrapper.find('#login-password').setValue('secret')
    await wrapper.find('form').trigger('submit')
    expect(wrapper.emitted('submit')).toBeTruthy()
    expect(wrapper.emitted('submit')![0]).toEqual([{ username: 'admin', password: 'secret' }])
  })

  it('displays error message when provided', async () => {
    const wrapper = await mountSuspended(LoginForm, {
      props: { error: 'Invalid credentials.' },
    })
    expect(wrapper.text()).toContain('Invalid credentials.')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('disables button when loading', async () => {
    const wrapper = await mountSuspended(LoginForm, {
      props: { loading: true },
    })
    const btn = wrapper.find('button[type="submit"]')
    expect(btn.attributes('disabled')).toBeDefined()
    expect(btn.text()).toContain('Signing in')
  })
})
