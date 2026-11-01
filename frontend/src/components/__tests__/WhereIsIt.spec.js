import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import WhereIsIt from '../WhereIsIt.vue'

const location = { area: 'shop', floor: 0, rack: '3', shelf: 2, code: 'SHOP-GF-R3-S2' }

const hammer = {
  id: 1,
  name: 'Claw Hammer',
  unit: { short_name: 'pc' },
  stock_quantity: 6,
  location,
  variants: [{ id: 11, name: null, selling_price: 260 }],
}

const paint = {
  id: 2,
  name: 'Wall Paint',
  unit: { short_name: 'pc' },
  stock_quantity: 14,
  location: { ...location, rack: null, shelf: null, code: 'SHOP-GF' },
  variants: [
    { id: 21, name: '1 L', selling_price: 360 },
    { id: 22, name: '4 L', selling_price: 1350 },
  ],
}

describe('WhereIsIt', () => {
  let fetchMock

  beforeEach(() => {
    vi.useFakeTimers()
    localStorage.setItem('admin_token', 'abc123')
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
    localStorage.clear()
  })

  async function mountAt(path, products) {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: products, meta: {}, summary: {} }), { status: 200 }),
    )

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: { template: '<div />' } },
        { path: '/products/:id', name: 'product-show', component: { template: '<div />' } },
      ],
    })
    await router.push(path)

    const wrapper = mount(WhereIsIt, {
      global: { plugins: [createPinia(), router] },
    })
    await flushPromises()

    return { wrapper, router }
  }

  async function searchFor(term, products) {
    const { wrapper } = await mountAt('/', products)

    await wrapper.get('input[type="search"]').setValue(term)
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()

    return wrapper
  }

  it('remembers the search in the address and shows it again when coming back', async () => {
    const { wrapper, router } = await mountAt('/', [hammer])

    await wrapper.get('input[type="search"]').setValue('hammer')
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    expect(router.currentRoute.value.query.find).toBe('hammer')

    const back = await mountAt('/?find=hammer', [hammer])
    expect(back.wrapper.get('input[type="search"]').element.value).toBe('hammer')
    expect(back.wrapper.get('.result .name').text()).toBe('Claw Hammer')
  })

  it('shows each product with its price, stock and location', async () => {
    const wrapper = await searchFor('hammer', [hammer])

    const result = wrapper.get('.result')
    expect(result.get('.name').text()).toBe('Claw Hammer')
    // Opens the product's own page, not its edit form.
    expect(result.get('a.name').attributes('href')).toBe('/products/1')
    expect(result.get('.prices').text()).toBe('₹260.00 per pc')
    expect(result.get('.stock').text()).toBe('6 pc in stock')
    expect(result.get('.code').text()).toBe('SHOP-GF-R3-S2')
    expect(result.get('.words').text()).toBe('Shop · Ground floor · Rack 3 · Shelf 2')
  })

  it('leaves out the size name when there is only one size', async () => {
    const earphone = {
      ...hammer,
      name: 'Apple Earphone',
      variants: [{ id: 31, name: 'Apple Earphone', selling_price: 1500 }],
    }
    const wrapper = await searchFor('earphone', [earphone])

    expect(wrapper.get('.prices').text()).toBe('₹1,500.00 per pc')
  })

  it('shows the price of every size', async () => {
    const wrapper = await searchFor('paint', [paint])

    const prices = wrapper.findAll('.price').map((price) => price.text().replace(/\s+/g, ' '))
    expect(prices).toEqual(['1 L ₹360.00', '4 L ₹1,350.00'])
  })

  it('says when nothing matches', async () => {
    const wrapper = await searchFor('zzz', [])

    expect(wrapper.text()).toContain('Nothing found for “zzz”.')
  })
})
