import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import ForgotPasswordForm from '~/components/auth/ForgotPasswordForm.vue'

describe('ForgotPasswordForm', () => {
  it('renders email field', async () => {
    const wrapper = await mountSuspended(ForgotPasswordForm)
    expect(wrapper.find('#forgot-email').exists()).toBe(true)
  })

  it('emits submit with email', async () => {
    const wrapper = await mountSuspended(ForgotPasswordForm)
    await wrapper.find('#forgot-email').setValue('user@example.com')
    await wrapper.find('form').trigger('submit')
    expect(wrapper.emitted('submit')).toBeTruthy()
    expect(wrapper.emitted('submit')![0]).toEqual([{ email: 'user@example.com' }])
  })

  it('displays error message when provided', async () => {
    const wrapper = await mountSuspended(ForgotPasswordForm, {
      props: { error: 'User not found.' },
    })
    expect(wrapper.text()).toContain('User not found.')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('displays success message and hides form fields', async () => {
    const wrapper = await mountSuspended(ForgotPasswordForm, {
      props: { success: 'Check your inbox for a reset link.' },
    })
    expect(wrapper.text()).toContain('Check your inbox for a reset link.')
    expect(wrapper.find('[role="status"]').exists()).toBe(true)
    expect(wrapper.find('#forgot-email').exists()).toBe(false)
    expect(wrapper.find('button[type="submit"]').exists()).toBe(false)
  })

  it('disables button when loading', async () => {
    const wrapper = await mountSuspended(ForgotPasswordForm, {
      props: { loading: true },
    })
    const btn = wrapper.find('button[type="submit"]')
    expect(btn.attributes('disabled')).toBeDefined()
    expect(btn.text()).toContain('Sending')
  })
})
