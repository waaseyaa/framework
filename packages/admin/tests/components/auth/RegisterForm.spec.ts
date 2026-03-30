import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import RegisterForm from '~/components/auth/RegisterForm.vue'

describe('RegisterForm', () => {
  it('renders all registration fields', async () => {
    const wrapper = await mountSuspended(RegisterForm)
    expect(wrapper.find('#register-name').exists()).toBe(true)
    expect(wrapper.find('#register-email').exists()).toBe(true)
    expect(wrapper.find('#register-password').exists()).toBe(true)
    expect(wrapper.find('#register-confirm-password').exists()).toBe(true)
  })

  it('renders labels for accessibility', async () => {
    const wrapper = await mountSuspended(RegisterForm)
    const labels = wrapper.findAll('label')
    expect(labels.length).toBe(4)
  })

  it('emits submit with all fields', async () => {
    const wrapper = await mountSuspended(RegisterForm)
    await wrapper.find('#register-name').setValue('Jane')
    await wrapper.find('#register-email').setValue('jane@example.com')
    await wrapper.find('#register-password').setValue('secret123')
    await wrapper.find('#register-confirm-password').setValue('secret123')
    await wrapper.find('form').trigger('submit')
    expect(wrapper.emitted('submit')).toBeTruthy()
    expect(wrapper.emitted('submit')![0]).toEqual([{
      name: 'Jane',
      email: 'jane@example.com',
      password: 'secret123',
      confirmPassword: 'secret123',
    }])
  })

  it('displays error message when provided', async () => {
    const wrapper = await mountSuspended(RegisterForm, {
      props: { error: 'Email already taken.' },
    })
    expect(wrapper.text()).toContain('Email already taken.')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('disables button when loading', async () => {
    const wrapper = await mountSuspended(RegisterForm, {
      props: { loading: true },
    })
    const btn = wrapper.find('button[type="submit"]')
    expect(btn.attributes('disabled')).toBeDefined()
    expect(btn.text()).toContain('Creating account')
  })

  it('disables inputs when loading', async () => {
    const wrapper = await mountSuspended(RegisterForm, {
      props: { loading: true },
    })
    const inputs = wrapper.findAll('input')
    inputs.forEach((input) => {
      expect(input.attributes('disabled')).toBeDefined()
    })
  })
})
