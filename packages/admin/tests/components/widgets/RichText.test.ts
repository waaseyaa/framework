import { afterEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { nextTick } from 'vue'
import RichText from '~/components/widgets/RichText.vue'
import { richTextHtmlFixtures } from '../../fixtures/richTextHtml'

async function mountRichText(props: Record<string, unknown> = {}, attachTo?: HTMLElement) {
  return mountSuspended(RichText, {
    attachTo,
    props: {
      modelValue: '<p>Body</p>',
      id: 'field-body',
      label: 'Body',
      description: 'Use headings to organize the page.',
      ...props,
    },
  })
}

function selectText(node: Node, start: number, end: number) {
  const range = document.createRange()
  range.setStart(node, start)
  range.setEnd(node, end)
  const selection = window.getSelection()!
  selection.removeAllRanges()
  selection.addRange(range)
}

function mockNativeEditing() {
  const execute = vi.fn((_command: string, _showUi: boolean, _value?: string) => {
    return window.getSelection()?.toString()
  })
  const properties = {
    execCommand: execute,
    queryCommandState: (command: string) => command === 'bold',
    queryCommandValue: () => 'p',
  }
  for (const [name, value] of Object.entries(properties)) {
    const original = Object.getOwnPropertyDescriptor(document, name)
    Object.defineProperty(document, name, { value, configurable: true })
    cleanup.push(() => original
      ? Object.defineProperty(document, name, original)
      : Reflect.deleteProperty(document, name))
  }
  return execute
}

const cleanup: Array<() => void> = []
afterEach(() => {
  window.getSelection()?.removeAllRanges()
  for (const release of cleanup.splice(0)) release()
})

async function mountInteractiveRichText(props: Record<string, unknown> = {}) {
  const wrapper = await mountRichText(props, document.body)
  cleanup.push(() => wrapper.unmount())
  return wrapper
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
    const sourceToggle = wrapper.get('.richtext-source-toggle')

    expect(editor.attributes('role')).toBe('textbox')
    expect(editor.attributes('aria-multiline')).toBe('true')
    expect(editor.attributes('contenteditable')).toBe('true')
    expect(sourceToggle.attributes('aria-controls')).toBe('field-body')
    expect(sourceToggle.attributes('aria-pressed')).toBe('false')
    expect(sourceToggle.classes()).toContain('touch-target')
    expect(sourceToggle.text().toLowerCase()).toContain('html source')

    await editor.trigger('keydown', { key: 's', ctrlKey: true, shiftKey: true })
    expect(wrapper.get('textarea').attributes('id')).toBe('field-body')
    expect(wrapper.get('.richtext-source-toggle').attributes('aria-pressed')).toBe('true')
  })

  it('exposes visual formatting controls only in visual mode', async () => {
    const wrapper = await mountRichText()
    expect(wrapper.get('[role="toolbar"]').findAll('button').map(button => button.text()))
      .toEqual(['Paragraph', 'Heading', 'Bold', 'Italic', 'Bulleted list', 'Numbered list', 'Link', 'Edit HTML source'])
    await wrapper.get('.richtext-source-toggle').trigger('click')
    expect(wrapper.findAll('[role="toolbar"] button')).toHaveLength(1)
  })

  it('makes disabled content non-editable in both visual and source modes', async () => {
    const wrapper = await mountRichText({ disabled: true })
    const editor = wrapper.get('[role="textbox"]')
    expect(editor.attributes('contenteditable')).toBe('false')
    expect(editor.attributes('aria-disabled')).toBe('true')
    expect(wrapper.get('.richtext-source-toggle').attributes('disabled')).toBeDefined()
  })

  it('discards an open link edit when the external value changes', async () => {
    const wrapper = await mountRichText({ modelValue: '<p><a href="/old">Old text</a></p>' })
    await wrapper.get('[contenteditable] a').trigger('click')
    expect((wrapper.get('#field-body-address').element as HTMLInputElement).value).toBe('/old')

    await wrapper.setProps({ modelValue: '<p>Replacement text</p>' })
    await nextTick()

    expect(wrapper.find('#field-body-link').exists()).toBe(false)
    expect(wrapper.get('[contenteditable]').text()).toBe('Replacement text')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('discards an open link edit when the field is disabled and stays closed when reenabled', async () => {
    const wrapper = await mountRichText({ modelValue: '<p><a href="/old">Old text</a></p>' })
    await wrapper.get('[contenteditable] a').trigger('click')
    expect(wrapper.find('#field-body-link').exists()).toBe(true)

    await wrapper.setProps({ disabled: true })
    await wrapper.setProps({ disabled: false })

    expect(wrapper.find('#field-body-link').exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('identifies active formats and restores the field selection before toolbar commands', async () => {
    const execute = mockNativeEditing()
    const wrapper = await mountInteractiveRichText()
    const editor = wrapper.get('[contenteditable]')
    const buttons = wrapper.get('[role="toolbar"]').findAll('button')
    const paragraph = buttons[0]!
    const bold = buttons[2]!
    selectText(editor.get('p').element.firstChild!, 0, 4)
    await editor.trigger('keyup')
    expect(paragraph.attributes('aria-pressed')).toBe('true')
    expect(bold.attributes('aria-pressed')).toBe('true')
    expect(buttons[3]!.attributes('aria-pressed')).toBe('false')

    window.getSelection()!.removeAllRanges()
    await bold.trigger('click')
    expect(execute).toHaveBeenLastCalledWith('bold', false, undefined)
    expect(execute.mock.results.at(-1)?.value).toBe('Body')

    await buttons[1]!.trigger('click')
    expect(execute).toHaveBeenLastCalledWith('formatBlock', false, 'h2')
    expect(execute.mock.results.at(-1)?.value).toBe('Body')
  })

  it('moves toolbar focus with arrows, Home and End using one tab stop', async () => {
    const wrapper = await mountInteractiveRichText()
    const buttons = wrapper.get('[role="toolbar"]').findAll('button')
    const first = buttons[0]!
    const second = buttons[1]!
    const last = buttons.at(-1)!
    expect(buttons.filter(button => button.attributes('tabindex') === '0')).toEqual([first])
    first.element.focus()
    await first.trigger('keydown', { key: 'ArrowRight' })
    expect(document.activeElement).toBe(second.element)
    expect(second.attributes('tabindex')).toBe('0')
    await second.trigger('keydown', { key: 'End' })
    expect(document.activeElement).toBe(last.element)
    await last.trigger('keydown', { key: 'ArrowRight' })
    expect(document.activeElement).toBe(first.element)
    await first.trigger('keydown', { key: 'ArrowLeft' })
    expect(document.activeElement).toBe(last.element)
    await last.trigger('keydown', { key: 'Home' })
    expect(document.activeElement).toBe(first.element)
  })

  it('opens link entry by keyboard and restores the selection on Escape', async () => {
    const execute = mockNativeEditing()
    const wrapper = await mountInteractiveRichText()
    const editor = wrapper.get('[contenteditable]')
    selectText(editor.get('p').element.firstChild!, 0, 4)
    await editor.trigger('keydown', { key: 'k', ctrlKey: true })
    const address = wrapper.get('#field-body-address')
    expect(document.activeElement).toBe(address.element)
    await editor.trigger('keydown', { key: 'k', metaKey: true })
    await address.trigger('keydown', { key: 'Escape' })
    expect(wrapper.find('#field-body-link').exists()).toBe(false)
    expect(document.activeElement).toBe(editor.element)
    expect(window.getSelection()?.toString()).toBe('Body')
    expect(execute).not.toHaveBeenCalled()
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('uses the original selection for link insertion after another selection', async () => {
    const execute = mockNativeEditing()
    const wrapper = await mountInteractiveRichText()
    const editor = wrapper.get('[contenteditable]')
    const node = editor.get('p').element.firstChild!
    selectText(node, 0, 4)
    await editor.trigger('keydown', { key: 'k', ctrlKey: true })
    const address = wrapper.get('#field-body-address')
    selectText(node, 0, 1)
    await editor.trigger('mouseup')
    await address.setValue('https://example.test/guide')
    await address.trigger('keydown', { key: 'Enter' })
    expect(execute).toHaveBeenCalledExactlyOnceWith('createLink', false, 'https://example.test/guide')
    expect(execute.mock.results[0]?.value).toBe('Body')
    expect(wrapper.find('#field-body-link').exists()).toBe(false)
  })

  it('inserts an escaped address label at the end when no text is selected', async () => {
    const execute = mockNativeEditing()
    const wrapper = await mountInteractiveRichText()
    await wrapper.get('button[aria-expanded]').trigger('click')
    const address = wrapper.get('#field-body-address')
    await address.setValue('https://example.test/?title=<guide>&format=pdf')
    await address.trigger('keydown', { key: 'Enter' })
    const [command, showUi, markup] = execute.mock.calls[0]!
    expect(command).toBe('insertHTML')
    expect(showUi).toBe(false)
    const template = document.createElement('template')
    template.innerHTML = markup!
    const link = template.content.querySelector('a')
    expect(link?.textContent).toBe('https://example.test/?title=<guide>&format=pdf')
    expect(link?.getAttribute('href')).toBe(link?.textContent)
    expect(template.content.querySelector('guide')).toBeNull()
  })

  it.each(['/guide', '#guide', '//example.test', 'unknown:guide', 'https:example.test', 'https://['])('rejects the invalid link address %s', async (value) => {
    const execute = mockNativeEditing()
    const wrapper = await mountInteractiveRichText()
    await wrapper.get('button[aria-expanded]').trigger('click')
    const address = wrapper.get('#field-body-address')
    await address.setValue(value)
    await address.trigger('keydown', { key: 'Enter' })
    expect(address.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('#field-body-link-error').attributes('role')).toBe('alert')
    expect(document.activeElement).toBe(address.element)
    expect(execute).not.toHaveBeenCalled()
  })

  it('selects the text of an existing link when removing it', async () => {
    const execute = mockNativeEditing()
    const wrapper = await mountInteractiveRichText({ modelValue: '<p>See <a href="/guide">the guide</a>.</p>' })
    const editor = wrapper.get('[contenteditable]')
    await editor.get('a').trigger('click')
    const remove = wrapper.findAll('#field-body-link button').find(button => button.text() === 'Remove link')!
    await remove.trigger('click')
    expect(execute).toHaveBeenCalledExactlyOnceWith('unlink', false, undefined)
    expect(execute.mock.results[0]?.value).toBe('the guide')
    expect(wrapper.find('#field-body-link').exists()).toBe(false)
  })

  it('ignores editor shortcuts and link activation when the field is disabled', async () => {
    const execute = mockNativeEditing()
    const wrapper = await mountInteractiveRichText({ modelValue: '<p><a href="/guide">Guide</a></p>', disabled: true })
    const editor = wrapper.get('[contenteditable]')
    expect(wrapper.get('[role="toolbar"]').findAll('button').every(button => button.element.disabled)).toBe(true)
    await editor.trigger('keydown', { key: 'k', metaKey: true })
    await editor.trigger('keydown', { key: 's', ctrlKey: true, shiftKey: true })
    await editor.get('a').trigger('click')
    expect(wrapper.find('#field-body-link').exists()).toBe(false)
    expect(wrapper.find('textarea').exists()).toBe(false)
    expect(execute).not.toHaveBeenCalled()
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

    await wrapper.get('.richtext-source-toggle').trigger('click')
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
      await wrapper.get('.richtext-source-toggle').trigger('click')
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

    await wrapper.get('.richtext-source-toggle').trigger('click')
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

    await wrapper.get('.richtext-source-toggle').trigger('click')
    expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe(html)
  })

  it('emits exact source edits and deterministic visual edits only after input', async () => {
    const wrapper = await mountRichText()
    await wrapper.get('.richtext-source-toggle').trigger('click')
    const source = wrapper.get('textarea')
    const sourceHtml = '<section data-legacy="yes"><p>Edited source</p></section>'
    await source.setValue(sourceHtml)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([sourceHtml])

    await wrapper.get('.richtext-source-toggle').trigger('click')
    const editor = wrapper.get('[contenteditable]')
    editor.element.innerHTML = '<p>Edited <em>visually</em></p>'
    await editor.trigger('input')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
      '<p>Edited <em>visually</em></p>',
    ])
  })
})
