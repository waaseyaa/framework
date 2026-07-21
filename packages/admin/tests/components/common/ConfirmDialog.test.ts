import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
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
})
