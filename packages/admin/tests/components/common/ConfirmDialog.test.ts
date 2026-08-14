import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { nextTick } from 'vue'
import ConfirmDialog from '~/components/common/ConfirmDialog.vue'

describe('ConfirmDialog', () => {
  it('renders an application modal and emits explicit decisions', async () => {
    const wrapper = await mountSuspended(ConfirmDialog, {
      props: { open: true, message: 'Delete this item?', dangerous: true },
      global: { stubs: { teleport: true } },
    })

    expect(wrapper.find('[role="alertdialog"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Delete this item?')
    await wrapper.get('[data-testid="confirm-dialog-cancel"]').trigger('click')
    await wrapper.get('[data-testid="confirm-dialog-confirm"]').trigger('click')
    expect(wrapper.emitted('cancel')).toHaveLength(1)
    expect(wrapper.emitted('confirm')).toHaveLength(1)
  })

  it('keeps keyboard focus inside the modal and permits Escape cancellation', async () => {
    const wrapper = await mountSuspended(ConfirmDialog, {
      props: { open: false, message: 'Remove this section?' },
      attachTo: document.body,
      global: { stubs: { teleport: true } },
    })
    await wrapper.setProps({ open: true })
    await nextTick()
    const cancel = wrapper.get<HTMLButtonElement>('[data-testid="confirm-dialog-cancel"]').element
    const confirm = wrapper.get<HTMLButtonElement>('[data-testid="confirm-dialog-confirm"]').element
    expect(document.activeElement).toBe(cancel)

    confirm.focus()
    await confirm.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }))
    expect(document.activeElement).toBe(cancel)
    await cancel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(wrapper.emitted('cancel')).toHaveLength(1)
    wrapper.unmount()
  })
})
