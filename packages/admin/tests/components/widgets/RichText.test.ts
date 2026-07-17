import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import RichText from '~/components/widgets/RichText.vue'
import { richTextHtmlFixtures } from '../../fixtures/richTextHtml'

async function mountRichText(props: Record<string, unknown> = {}) {
  return mountSuspended(RichText, {
    props: {
      modelValue: '<p>Body</p>',
      id: 'field-body',
      label: 'Body',
      description: 'Use headings to organize the page.',
      ...props,
    },
  })
}

describe('RichText', () => {
  it('programmatically associates its label, help, required state, and error', async () => {
    const wrapper = await mountRichText({ required: true, error: 'Body is required.' })
    const editor = wrapper.get('[contenteditable]')

    expect(wrapper.get('label').attributes('for')).toBe('field-body')
    expect(editor.attributes('id')).toBe('field-body')
    expect(editor.attributes('aria-labelledby')).toBe('field-body-label')
    expect(editor.attributes('aria-describedby')?.split(' ')).toEqual([
      'field-body-description',
      'field-body-error',
    ])
    expect(editor.attributes('aria-required')).toBe('true')
    expect(editor.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('#field-body-error').attributes('role')).toBe('alert')
    expect(wrapper.get('.required').attributes('aria-hidden')).toBe('true')
    expect(wrapper.text()).toContain('required')
  })

  it('omits invalid and required ARIA states when they do not apply', async () => {
    const editor = (await mountRichText()).get('[contenteditable]')
    expect(editor.attributes('aria-invalid')).toBeUndefined()
    expect(editor.attributes('aria-required')).toBeUndefined()
  })

  it('uses parent-provided help/error IDs and described-by ordering across rerenders', async () => {
    const wrapper = await mountRichText({
      descriptionId: 'schema-body-help',
      error: 'Fix the body.',
      errorId: 'schema-body-error',
      describedBy: 'schema-body-error schema-body-help',
    })
    const editor = wrapper.get('[contenteditable]')
    expect(editor.attributes('aria-describedby')).toBe('schema-body-error schema-body-help')
    expect(wrapper.get('#schema-body-help').text()).toContain('Use headings')
    expect(wrapper.get('#schema-body-error').text()).toContain('Fix the body')

    await wrapper.setProps({ error: undefined, describedBy: 'schema-body-help' })
    expect(wrapper.get('[contenteditable]').attributes('aria-describedby')).toBe('schema-body-help')
    expect(wrapper.find('#schema-body-error').exists()).toBe(false)
  })

  it('exposes explicit multiline textbox semantics and a documented source control', async () => {
    const wrapper = await mountRichText()
    const editor = wrapper.get('[contenteditable]')
    const sourceToggle = wrapper.get('button')

    expect(editor.attributes('role')).toBe('textbox')
    expect(editor.attributes('aria-multiline')).toBe('true')
    expect(editor.attributes('contenteditable')).toBe('true')
    expect(sourceToggle.attributes('aria-controls')).toBe('field-body')
    expect(sourceToggle.attributes('aria-pressed')).toBe('false')
    expect(sourceToggle.classes()).toContain('touch-target')
    expect(sourceToggle.text().toLowerCase()).toContain('html source')

    await editor.trigger('keydown', { key: 's', ctrlKey: true, shiftKey: true })
    expect(wrapper.get('textarea').attributes('id')).toBe('field-body')
    expect(wrapper.get('button').attributes('aria-pressed')).toBe('true')
  })

  it('makes disabled content non-editable in both visual and source modes', async () => {
    const wrapper = await mountRichText({ disabled: true })
    const editor = wrapper.get('[role="textbox"]')
    expect(editor.attributes('contenteditable')).toBe('false')
    expect(editor.attributes('aria-disabled')).toBe('true')
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
  })

  it.each([
    ['headings and paragraphs', richTextHtmlFixtures.headingsAndParagraphs],
    ['links', richTextHtmlFixtures.links],
    ['lists', richTextHtmlFixtures.lists],
    ['images and attributes', richTextHtmlFixtures.image],
    ['supported iframe/embed markup', richTextHtmlFixtures.embed],
    ['unfamiliar valid attributes', richTextHtmlFixtures.unfamiliarAttributes],
  ])('preserves untouched migrated %s byte-for-byte in source mode', async (_name, html) => {
    const wrapper = await mountRichText({ modelValue: html })
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()

    await wrapper.get('button').trigger('click')
    expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe(html)

    await wrapper.setProps({ modelValue: `${html}\n` })
    expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe(`${html}\n`)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it.each([richTextHtmlFixtures.empty, richTextHtmlFixtures.null])(
    'handles empty/null content without placeholder markup or emission',
    async (modelValue) => {
      const wrapper = await mountRichText({ modelValue })
      expect(wrapper.get('[contenteditable]').element.innerHTML).toBe('')
      expect(wrapper.emitted('update:modelValue')).toBeUndefined()
      await wrapper.get('button').trigger('click')
      expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('')
    },
  )

  it('keeps active or remote markup inert in visual mode and exact in source mode', async () => {
    const wrapper = await mountRichText({ modelValue: richTextHtmlFixtures.unsafeActiveContent })
    const visualHtml = wrapper.get('[contenteditable]').element.innerHTML
    expect(visualHtml).not.toContain('<script')
    expect(visualHtml).not.toContain('onclick=')
    expect(visualHtml).not.toContain('src="https://')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()

    await wrapper.get('button').trigger('click')
    expect((wrapper.get('textarea').element as HTMLTextAreaElement).value)
      .toBe(richTextHtmlFixtures.unsafeActiveContent)
  })

  it('removes document-level fetch/redirect markup and active SVG/MathML subtrees from visual mode', async () => {
    const html = '<link rel="stylesheet" href="https://example.invalid/a.css"><meta http-equiv="refresh" content="0;url=https://example.invalid/"><svg><a href="https://example.invalid/"><text>active</text></a></svg><math><annotation-xml encoding="text/html"><img src="https://example.invalid/a.png"></annotation-xml></math><p>Safe text</p>'
    const wrapper = await mountRichText({ modelValue: html })
    const visualHtml = wrapper.get('[contenteditable]').element.innerHTML.toLowerCase()

    expect(visualHtml).not.toContain('<link')
    expect(visualHtml).not.toContain('<meta')
    expect(visualHtml).not.toContain('<svg')
    expect(visualHtml).not.toContain('<math')
    expect(visualHtml).not.toContain('https://example.invalid')
    expect(visualHtml).toContain('<p>safe text</p>')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()

    await wrapper.get('button').trigger('click')
    expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe(html)
  })

  it('emits exact source edits and deterministic visual edits only after input', async () => {
    const wrapper = await mountRichText()
    await wrapper.get('button').trigger('click')
    const source = wrapper.get('textarea')
    const sourceHtml = '<section data-legacy="yes"><p>Edited source</p></section>'
    await source.setValue(sourceHtml)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([sourceHtml])

    await wrapper.get('button').trigger('click')
    const editor = wrapper.get('[contenteditable]')
    editor.element.innerHTML = '<p>Edited <em>visually</em></p>'
    await editor.trigger('input')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
      '<p>Edited <em>visually</em></p>',
    ])
  })
})
