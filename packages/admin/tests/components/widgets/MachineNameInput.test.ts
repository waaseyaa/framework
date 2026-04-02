import { computed, defineComponent, h, provide, ref } from 'vue'
import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import MachineNameInput from '~/components/widgets/MachineNameInput.vue'
import { schemaFormContextKey } from '~/components/schema/schemaFormContext'

type ContextOptions = {
  formData?: Record<string, any>
  isEditMode?: boolean
  props?: Record<string, unknown>
}

async function mountWithContext(options: ContextOptions = {}) {
  const formData = ref<Record<string, any>>(options.formData ?? {})
  const isEditMode = ref(options.isEditMode ?? false)

  const Host = defineComponent({
    setup() {
      provide(schemaFormContextKey, {
        formData,
        isEditMode: computed(() => isEditMode.value),
      })

      return () => h(MachineNameInput, {
        modelValue: '',
        schema: {
          type: 'string',
          'x-widget': 'machine_name',
          'x-source-field': 'name',
        },
        ...options.props,
      })
    },
  })

  const wrapper = await mountSuspended(Host)
  return {
    wrapper,
    input: wrapper.findComponent(MachineNameInput),
    formData,
    isEditMode,
  }
}

describe('MachineNameInput', () => {
  it('throws when mounted outside SchemaForm context', async () => {
    await expect(mountSuspended(MachineNameInput, {
      props: {
        modelValue: '',
        schema: {
          type: 'string',
          'x-widget': 'machine_name',
          'x-source-field': 'name',
        },
      },
    })).rejects.toThrow('[MachineNameInput] Missing SchemaForm provider context.')
  })

  it('throws when x-source-field is missing', async () => {
    await expect(mountWithContext({
      props: {
        schema: {
          type: 'string',
          'x-widget': 'machine_name',
        },
      },
    })).rejects.toThrow('[MachineNameInput] machine_name widgets require x-source-field.')
  })

  it('locks deterministically in edit mode', async () => {
    const { input } = await mountWithContext({
      isEditMode: true,
      formData: { name: 'Article' },
    })

    expect(input.find('.field-input--machine-name').attributes('disabled')).toBeDefined()
  })

  it('auto-generates deterministically from the source field', async () => {
    const { input, formData } = await mountWithContext({
      formData: { name: 'Article Type' },
    })
    await flushPromises()

    expect(input.emitted('update:modelValue')?.[0]).toEqual(['article_type'])

    formData.value.name = 'Landing Page'
    await flushPromises()

    expect(input.emitted('update:modelValue')?.[1]).toEqual(['landing_page'])
  })

  it('stops auto-generation after manual edit', async () => {
    const { input, formData } = await mountWithContext({
      formData: { name: 'Article Type' },
    })
    await flushPromises()

    await input.find('.field-input--machine-name').setValue('custom_value')
    await flushPromises()

    formData.value.name = 'Landing Page'
    await flushPromises()

    expect(input.emitted('update:modelValue')).toEqual([
      ['article_type'],
      ['custom_value'],
    ])
  })
})
