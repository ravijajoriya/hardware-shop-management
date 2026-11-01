import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

import InfoTip from '../InfoTip.vue'
import { glossary } from '@/utils/glossary'

describe('InfoTip', () => {
  let wrapper

  afterEach(() => {
    wrapper?.unmount()
  })

  it('shows an "i" button named after the term, with the explanation hidden', () => {
    wrapper = mount(InfoTip, { props: { term: 'SKU' } })

    const button = wrapper.get('button')
    expect(button.text()).toBe('i')
    expect(button.attributes('aria-label')).toBe('What is SKU?')
    expect(wrapper.find('[role="tooltip"]').exists()).toBe(false)
  })

  it('explains a short form with its full name when focused', async () => {
    wrapper = mount(InfoTip, { props: { term: 'MRP' }, attachTo: document.body })

    await wrapper.get('button').trigger('focus')

    const tooltip = wrapper.get('[role="tooltip"]')
    expect(tooltip.text()).toContain('MRP · Maximum Retail Price')
    expect(tooltip.text()).toContain(glossary.MRP.meaning)
    expect(wrapper.get('button').attributes('aria-describedby')).toBe(tooltip.attributes('id'))
  })

  it('opens on tap or hover and closes on Escape or moving away', async () => {
    wrapper = mount(InfoTip, { props: { term: 'HSN' }, attachTo: document.body })
    const button = wrapper.get('button')

    await button.trigger('click')
    expect(wrapper.find('[role="tooltip"]').exists()).toBe(true)

    await button.trigger('keydown', { key: 'Escape' })
    expect(wrapper.find('[role="tooltip"]').exists()).toBe(false)

    await wrapper.trigger('mouseenter')
    expect(wrapper.find('[role="tooltip"]').exists()).toBe(true)

    await wrapper.trigger('mouseleave')
    expect(wrapper.find('[role="tooltip"]').exists()).toBe(false)
  })

  it('shows a term without a full name on its own, and accepts custom text', async () => {
    wrapper = mount(InfoTip, {
      props: { term: 'Slug', text: 'Used in links.' },
      attachTo: document.body,
    })

    await wrapper.get('button').trigger('click')

    const heading = wrapper.get('[role="tooltip"] strong')
    expect(heading.text()).toBe('Slug')
    expect(wrapper.get('[role="tooltip"]').text()).toContain('Used in links.')
  })

  it('has a plain explanation for every term in the glossary', () => {
    for (const [term, entry] of Object.entries(glossary)) {
      // The term is in the compared object so a failure names it.
      expect({ term, meaning: entry.meaning }).toEqual({
        term,
        meaning: expect.stringMatching(/\.$/),
      })
    }
  })
})
