import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import FileUpload from '~/components/widgets/FileUpload.vue'
import { schemaFormContextKey } from '~/components/schema/schemaFormContext'
import { computed, ref } from 'vue'

class MockXMLHttpRequest {
  static instances: MockXMLHttpRequest[] = []
  static status = 201
  static responseText = JSON.stringify({
    data: { attributes: { uri: 'public://test.png' } },
  })
  static autoComplete = true

  status = MockXMLHttpRequest.status
  responseText = MockXMLHttpRequest.responseText

  upload = {
    addEventListener: vi.fn((event: string, cb: (e: ProgressEvent) => void) => {
      if (event === 'progress') {
        const progressEvent = { lengthComputable: true, loaded: 50, total: 100 } as ProgressEvent
        cb(progressEvent)
      }
    }),
  }

  private listeners: Record<string, (() => void)[]> = {}

  open = vi.fn()
  setRequestHeader = vi.fn()
  send = vi.fn(() => {
    if (MockXMLHttpRequest.autoComplete) this.dispatch('load')
  })

  withCredentials = false

  addEventListener(event: string, cb: () => void) {
    if (!this.listeners[event]) {
      this.listeners[event] = []
    }
    this.listeners[event].push(cb)
  }

  constructor() {
    MockXMLHttpRequest.instances.push(this)
  }

  dispatch(event: string) {
    for (const cb of this.listeners[event] ?? []) {
      cb()
    }
  }
}

describe('FileUpload widget', () => {
  const originalXhr = globalThis.XMLHttpRequest
  const originalCreateObjectUrl = URL.createObjectURL
  const originalRevokeObjectUrl = URL.revokeObjectURL

  beforeEach(() => {
    MockXMLHttpRequest.instances = []
    MockXMLHttpRequest.status = 201
    MockXMLHttpRequest.responseText = JSON.stringify({ data: { attributes: { uri: 'public://test.png' } } })
    MockXMLHttpRequest.autoComplete = true
    vi.stubGlobal('XMLHttpRequest', MockXMLHttpRequest as unknown as typeof XMLHttpRequest)
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      meta: { constraints: { max_bytes: 1024, allowed_mime_types: ['image/png', 'application/pdf'] } },
    }), { status: 200, headers: { 'Content-Type': 'application/vnd.api+json' } })))
    URL.createObjectURL = vi.fn(() => 'blob:preview')
    URL.revokeObjectURL = vi.fn()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    globalThis.XMLHttpRequest = originalXhr
    URL.createObjectURL = originalCreateObjectUrl
    URL.revokeObjectURL = originalRevokeObjectUrl
  })

  function mountWidget(overrides: Record<string, unknown> = {}) {
    return mountSuspended(FileUpload, {
      props: { modelValue: '', label: 'Upload', ...overrides },
      global: {
        provide: {
          [schemaFormContextKey as symbol]: {
            formData: ref({ bundle: 'image' }),
            isEditMode: computed(() => false),
            entityType: 'media',
            bundleKey: computed(() => 'bundle'),
            selectedBundle: computed(() => 'image'),
          },
        },
      },
    })
  }

  async function choose(wrapper: Awaited<ReturnType<typeof mountWidget>>, file = new File(['test'], 'hero.png', { type: 'image/png' })) {
    const input = wrapper.find('input[type="file"]')
    await vi.waitFor(() => expect(input.attributes('disabled')).toBeUndefined())
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
  }

  it('uploads to the canonical API with the selected bundle and emits the canonical URI', async () => {
    document.cookie = 'XSRF-TOKEN=test-token'
    const wrapper = await mountWidget()
    await choose(wrapper)

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['public://test.png'])
    const xhr = MockXMLHttpRequest.instances[0]!
    expect(xhr.open).toHaveBeenCalledWith('POST', '/api/media/upload', true)
    expect(xhr.setRequestHeader).toHaveBeenCalledWith('X-XSRF-TOKEN', 'test-token')
    const body = xhr.send.mock.calls[0]?.[0] as FormData
    expect(body.get('bundle')).toBe('image')
    expect(URL.createObjectURL).toHaveBeenCalled()
    expect(wrapper.find('img').exists()).toBe(true)
  })

  it('programmatically associates the visible label and announces failures', async () => {
    MockXMLHttpRequest.status = 403
    const wrapper = await mountWidget()
    await choose(wrapper)
    const input = wrapper.find('input[type="file"]')
    expect(wrapper.find('label').attributes('for')).toBe(input.attributes('id'))
    const alert = wrapper.find('[role="alert"]')
    expect(alert.exists()).toBe(true)
    expect(alert.text()).toMatch(/not permitted|permission|forbidden/i)
  })

  it('reports server constraints and rejects unsupported or excessive files before upload', async () => {
    const wrapper = await mountWidget()
    await vi.waitFor(() => expect(wrapper.find('input[type="file"]').attributes('accept')).toContain('image/png'))
    expect(wrapper.text()).toMatch(/1 KB|1024/)

    await choose(wrapper, new File(['plain'], 'note.txt', { type: 'text/plain' }))
    expect(MockXMLHttpRequest.instances).toHaveLength(0)
    expect(wrapper.find('[role="alert"]').text()).toMatch(/type|supported/i)

    const large = new File([new Uint8Array(2048)], 'large.png', { type: 'image/png' })
    await choose(wrapper, large)
    expect(MockXMLHttpRequest.instances).toHaveLength(0)
    expect(wrapper.find('[role="alert"]').text()).toMatch(/large|size/i)
  })

  it('accepts a MIME type covered by a server wildcard constraint', async () => {
    vi.mocked(fetch).mockResolvedValueOnce(new Response(JSON.stringify({
      meta: { constraints: { max_bytes: 1024, allowed_mime_types: ['image/*'] } },
    }), { status: 200, headers: { 'Content-Type': 'application/vnd.api+json' } }))
    const wrapper = await mountWidget()
    await choose(wrapper, new File(['png'], 'hero.png', { type: 'image/png' }))
    expect(MockXMLHttpRequest.instances).toHaveLength(1)
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['public://test.png'])
  })

  it('rejects malformed success responses without exposing backend values', async () => {
    MockXMLHttpRequest.responseText = JSON.stringify({ data: { attributes: { path: '/srv/private/upload' } } })
    const wrapper = await mountWidget()
    await choose(wrapper)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(wrapper.text()).toMatch(/invalid|unexpected/i)
    expect(wrapper.text()).not.toContain('/srv/private/upload')
  })

  it('prevents duplicate submissions while an upload is pending', async () => {
    MockXMLHttpRequest.autoComplete = false
    const wrapper = await mountWidget()
    await choose(wrapper)
    await wrapper.find('input[type="file"]').trigger('change')
    expect(MockXMLHttpRequest.instances).toHaveLength(1)
    expect(wrapper.find('input[type="file"]').attributes('disabled')).toBeDefined()
  })

  it.each([
    [401, /sign in|authentication/i],
    [403, /not permitted|permission|forbidden/i],
    [422, /validation|not accepted/i],
    [500, /server|try again/i],
  ])('distinguishes HTTP %s without echoing server detail', async (status, message) => {
    MockXMLHttpRequest.status = status
    MockXMLHttpRequest.responseText = JSON.stringify({ errors: [{ detail: '/srv/secret: stack trace' }] })
    const wrapper = await mountWidget()
    await choose(wrapper)
    expect(wrapper.find('[role="alert"]').text()).toMatch(message)
    expect(wrapper.text()).not.toContain('/srv/secret')
  })

  it('distinguishes network failure and never claims success', async () => {
    MockXMLHttpRequest.autoComplete = false
    const wrapper = await mountWidget()
    await choose(wrapper)
    MockXMLHttpRequest.instances[0]!.dispatch('error')
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[role="alert"]').text()).toMatch(/network|connection/i)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })
})
