import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import VerificationBanner from '~/components/auth/VerificationBanner.vue'

const mockResendVerification = vi.fn()
const mockCurrentUser = ref<{ id: number; emailVerified: boolean } | null>(null)

vi.mock('~/composables/useAuth', () => ({
  useAuth: () => ({
    currentUser: mockCurrentUser,
    resendVerification: mockResendVerification,
  }),
}))

const localStorageMap = new Map<string, string>()
const localStorageMock = {
  getItem: vi.fn((key: string) => localStorageMap.get(key) ?? null),
  setItem: vi.fn((key: string, value: string) => localStorageMap.set(key, value)),
  removeItem: vi.fn((key: string) => localStorageMap.delete(key)),
  clear: vi.fn(() => localStorageMap.clear()),
  get length() { return localStorageMap.size },
  key: vi.fn((_index: number) => null),
}
vi.stubGlobal('localStorage', localStorageMock)

describe('VerificationBanner', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorageMap.clear()
    mockCurrentUser.value = null
  })

  it('is hidden when no user is logged in', async () => {
    mockCurrentUser.value = null
    const wrapper = await mountSuspended(VerificationBanner)
    expect(wrapper.find('.verification-banner').exists()).toBe(false)
  })

  it('is hidden when user email is already verified', async () => {
    mockCurrentUser.value = { id: 1, emailVerified: true }
    const wrapper = await mountSuspended(VerificationBanner)
    expect(wrapper.find('.verification-banner').exists()).toBe(false)
  })

  it('is visible when user email is not verified', async () => {
    mockCurrentUser.value = { id: 1, emailVerified: false }
    const wrapper = await mountSuspended(VerificationBanner)
    expect(wrapper.find('.verification-banner').exists()).toBe(true)
    expect(wrapper.text()).toContain('Please verify your email address')
  })

  it('dismisses when close button is clicked', async () => {
    mockCurrentUser.value = { id: 1, emailVerified: false }
    const wrapper = await mountSuspended(VerificationBanner)
    await wrapper.find('.banner-dismiss').trigger('click')
    expect(wrapper.find('.verification-banner').exists()).toBe(false)
    expect(localStorageMock.setItem).toHaveBeenCalledWith('waaseyaa.verify.dismissed.1', '1')
  })

  it('stays dismissed via localStorage', async () => {
    localStorageMap.set('waaseyaa.verify.dismissed.1', '1')
    mockCurrentUser.value = { id: 1, emailVerified: false }
    const wrapper = await mountSuspended(VerificationBanner)
    expect(wrapper.find('.verification-banner').exists()).toBe(false)
  })

  it('calls resendVerification on resend click', async () => {
    mockResendVerification.mockResolvedValue({ ok: true })
    mockCurrentUser.value = { id: 1, emailVerified: false }
    const wrapper = await mountSuspended(VerificationBanner)
    await wrapper.find('.banner-resend').trigger('click')
    await flushPromises()
    expect(mockResendVerification).toHaveBeenCalledOnce()
  })

  it('shows success message after resend', async () => {
    mockResendVerification.mockResolvedValue({ ok: true })
    mockCurrentUser.value = { id: 1, emailVerified: false }
    const wrapper = await mountSuspended(VerificationBanner)
    await wrapper.find('.banner-resend').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Email sent')
  })

  it('shows error message on resend failure', async () => {
    mockResendVerification.mockResolvedValue({ ok: false, error: 'Too many attempts.' })
    mockCurrentUser.value = { id: 1, emailVerified: false }
    const wrapper = await mountSuspended(VerificationBanner)
    await wrapper.find('.banner-resend').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Too many attempts.')
  })
})
