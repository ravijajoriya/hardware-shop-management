import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import ProductImagesField from '../ProductImagesField.vue'
import { compressImage } from '@/utils/images'

vi.mock('@/utils/images', () => ({ compressImage: vi.fn() }))

function photo(name, type, size) {
  return new File(['x'.repeat(size)], name, { type })
}

function mountField(items = []) {
  return mount(ProductImagesField, {
    props: {
      modelValue: items,
      'onUpdate:modelValue': (value) => wrapper.setProps({ modelValue: value }),
    },
  })
}

let wrapper

async function choose(files) {
  const input = wrapper.get('input[type="file"]')
  Object.defineProperty(input.element, 'files', { value: files, configurable: true })
  await input.trigger('change')
}

describe('ProductImagesField', () => {
  beforeEach(() => {
    vi.stubGlobal('URL', { createObjectURL: () => 'blob:preview', revokeObjectURL: vi.fn() })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    compressImage.mockReset()
  })

  it('makes chosen photos smaller before adding them', async () => {
    const small = photo('front.webp', 'image/webp', 300_000)
    compressImage.mockResolvedValue(small)
    wrapper = mountField()

    await choose([photo('front.jpg', 'image/jpeg', 4_000_000)])
    await flushPromises()

    expect(compressImage).toHaveBeenCalledTimes(1)
    expect(wrapper.props('modelValue')).toHaveLength(1)
    expect(wrapper.props('modelValue')[0].file).toBe(small)
    expect(wrapper.find('.field-error').exists()).toBe(false)
  })

  it('tells the form to wait while the photos are being made smaller', async () => {
    let finish
    compressImage.mockReturnValue(new Promise((resolve) => (finish = resolve)))
    wrapper = mountField()

    await choose([photo('front.jpg', 'image/jpeg', 4_000_000)])

    expect(wrapper.emitted('update:preparing')).toEqual([[true]])
    expect(wrapper.text()).toContain('Making the photos smaller…')

    finish(photo('front.webp', 'image/webp', 300_000))
    await flushPromises()

    expect(wrapper.emitted('update:preparing')).toEqual([[true], [false]])
  })

  it('skips files that are not photos, and photos still too big after shrinking', async () => {
    compressImage.mockImplementation(async (file) =>
      file.name === 'poster.png' ? file : photo('front.webp', 'image/webp', 300_000),
    )
    wrapper = mountField()

    await choose([
      photo('notes.pdf', 'application/pdf', 1000),
      photo('front.jpg', 'image/jpeg', 4_000_000),
      photo('poster.png', 'image/png', 3_000_000),
    ])
    await flushPromises()

    expect(compressImage).toHaveBeenCalledTimes(2)
    expect(wrapper.props('modelValue')).toHaveLength(1)
    expect(wrapper.get('.field-error').text()).toBe(
      'Some files were skipped: use JPG, PNG or WebP photos. Some photos were skipped: they are still over 2 MB after making them smaller.',
    )
  })
})
