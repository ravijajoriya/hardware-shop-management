import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import ProductGallery from '../ProductGallery.vue'

const images = [
  { id: 1, url: 'http://localhost/front.jpg' },
  { id: 2, url: 'http://localhost/side.jpg' },
  { id: 3, url: 'http://localhost/back.jpg' },
]

describe('ProductGallery', () => {
  it('shows the main image large, with a thumbnail for each photo', () => {
    const wrapper = mount(ProductGallery, { props: { images, name: 'Drill' } })

    const main = wrapper.get('.main img')
    expect(main.attributes('src')).toBe(images[0].url)
    expect(main.attributes('alt')).toBe('Drill, photo 1 of 3')
    expect(wrapper.get('a.main').attributes('href')).toBe(images[0].url)
    expect(wrapper.findAll('.thumb')).toHaveLength(3)
  })

  it('switches the large image when a thumbnail is picked', async () => {
    const wrapper = mount(ProductGallery, { props: { images, name: 'Drill' } })

    await wrapper.findAll('.thumb')[2].trigger('click')

    expect(wrapper.get('.main img').attributes('src')).toBe(images[2].url)
    expect(wrapper.findAll('.thumb')[2].attributes('aria-pressed')).toBe('true')
    expect(wrapper.findAll('.thumb')[0].attributes('aria-pressed')).toBe('false')
  })

  it('needs no thumbnails for a single photo, and says when there are none', () => {
    const one = mount(ProductGallery, { props: { images: [images[0]], name: 'Drill' } })
    expect(one.find('.thumbs').exists()).toBe(false)

    const none = mount(ProductGallery, { props: { images: [], name: 'Drill' } })
    expect(none.get('.main').text()).toBe('No photos yet')
  })
})
