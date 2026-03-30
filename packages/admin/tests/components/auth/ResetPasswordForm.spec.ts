import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import ResetPasswordForm from '~/components/auth/ResetPasswordForm.vue'

describe('ResetPasswordForm', () => {
  it('renders password and confirm password fields', async () => {
    const wrapper = await mountSuspended(ResetPasswordForm)
    expect(wrapper.find('#reset-password').exists()).toBe(true)
    expect(wrapper.find('#reset-confirm-password').exists()).toBe(true)
  })

  it('renders labels for accessibility', async () => {
    const wrapper = await mountSuspended(ResetPasswordForm)
    const labels = wrapper.findAll('label')
    expect(labels.length).toBe(2)
  })

  it('emits submit with password and confirmation', async () => {
    const wrapper = await mountSuspended(ResetPasswordForm)
    await wrapper.find('#reset-password').setValue('newpass123')
    await wrapper.find('#reset-confirm-password').setValue('newpass123')
    await wrapper.find('form').trigger('submit')
    expect(wrapper.emitted('submit')).toBeTruthy()
    expect(wrapper.emitted('submit')![0]).toEqual([{
      password: 'newpass123',
      confirmPassword: 'newpass123',
    }])
  })

  it('displays error message when provided', async () => {
    const wrapper = await mountSuspended(ResetPasswordForm, {
      props: { error: 'Token expired.' },
    })
    expect(wrapper.text()).toContain('Token expired.')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('disables button when loading', async () => {
    const wrapper = await mountSuspended(ResetPasswordForm, {
      props: { loading: true },
    })
    const btn = wrapper.find('button[type="submit"]')
    expect(btn.attributes('disabled')).toBeDefined()
    expect(btn.text()).toContain('Resetting')
  })

  it('disables inputs when loading', async () => {
    const wrapper = await mountSuspended(ResetPasswordForm, {
      props: { loading: true },
    })
    const inputs = wrapper.findAll('input')
    inputs.forEach((input) => {
      expect(input.attributes('disabled')).toBeDefined()
    })
  })
})
