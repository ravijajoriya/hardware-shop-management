import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import ProductDetailView from '../ProductDetailView.vue'

const blank = { template: '<div />' }

const paint = {
  id: 5,
  name: 'Wall Paint',
  description: 'Weatherproof.',
  category: { id: 1, name: 'Paint' },
  brand: { id: 2, name: 'Asian Paints' },
  unit: { id: 3, name: 'Piece', short_name: 'pc', allow_decimal: false },
  location: { area: 'shop', floor: 0, rack: '3', shelf: 2, code: 'SHOP-GF-R3-S2' },
  hsn_code: '3209',
  gst_rate: 18,
  is_active: true,
  images: [
    { id: 1, url: 'http://localhost/front.jpg' },
    { id: 2, url: 'http://localhost/side.jpg' },
  ],
  stock_quantity: 14,
  variants: [
    { id: 21, name: '1 L', sku: 'AP-1L', selling_price: 360, mrp: 399, stock_quantity: 10 },
    { id: 22, name: '4 L', sku: null, selling_price: 1350, mrp: null, stock_quantity: 4 },
  ],
}

const earphone = {
  ...paint,
  id: 6,
  name: 'Earphone',
  brand: null,
  hsn_code: null,
  gst_rate: null,
  images: [],
  stock_quantity: 100,
  variants: [
    { id: 31, name: 'Earphone', sku: null, selling_price: 1500, mrp: 1999, stock_quantity: 100 },
  ],
}

function jsonResponse(status, body) {
  return new Response(JSON.stringify(body), { status })
}

describe('ProductDetailView', () => {
  let fetchMock

  beforeEach(() => {
    localStorage.setItem('admin_token', 'abc123')
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    localStorage.clear()
  })

  async function open(response) {
    fetchMock.mockResolvedValue(response)

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'dashboard', component: blank },
        { path: '/products', name: 'products', component: blank },
        { path: '/products/:id', name: 'product-show', component: blank },
        { path: '/products/:id/edit', name: 'product-edit', component: blank },
      ],
    })

    const wrapper = mount(ProductDetailView, {
      props: { id: '5' },
      global: { plugins: [createPinia(), router] },
    })
    await flushPromises()

    return wrapper
  }

  it('shows the product with its photos, prices, stock and place', async () => {
    const wrapper = await open(jsonResponse(200, { data: paint }))

    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/products\/5$/)
    expect(wrapper.get('h1').text()).toBe('Wall Paint')
    expect(wrapper.get('.subtitle').text()).toContain('Asian Paints · Paint')
    expect(wrapper.findAll('.thumb')).toHaveLength(2)
    expect(wrapper.get('.amount').text()).toBe('₹360.00 – ₹1,350.00')
    expect(wrapper.get('.stock').text()).toBe('14 pc in stock')
    expect(wrapper.get('.place .code').text()).toBe('SHOP-GF-R3-S2')
    expect(wrapper.get('.place .words').text()).toBe('Shop · Ground floor · Rack 3 · Shelf 2')
    expect(wrapper.text()).toContain('Weatherproof.')

    const rows = wrapper.findAll('tbody tr').map((row) => row.findAll('td').map((td) => td.text()))
    expect(rows).toEqual([
      ['1 L', 'AP-1L', '₹360.00', '₹399.00', '10 pc'],
      ['4 L', '—', '₹1,350.00', '—', '4 pc'],
    ])

    expect(wrapper.get('a.btn-primary').attributes('href')).toBe('/products/5/edit')
  })

  it('shows one price per unit, with its MRP, when there is only one size', async () => {
    const wrapper = await open(jsonResponse(200, { data: earphone }))

    expect(wrapper.get('.amount').text()).toBe('₹1,500.00')
    expect(wrapper.get('.price .note').text()).toBe('per pc')
    expect(wrapper.get('.mrp').text()).toContain('₹1,999.00')
    expect(wrapper.get('.main').text()).toBe('No photos yet')
    expect(wrapper.findAll('thead th')).toHaveLength(4)
    expect(wrapper.text()).toContain('No brand')
    expect(wrapper.text()).toContain('Not set')
  })

  it('says so when the product has been deleted', async () => {
    const wrapper = await open(jsonResponse(404, { message: 'No query results for model.' }))

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toContain('This product no longer exists.')
    // Trying again cannot help, so it points to the product list instead.
    expect(alert.get('a').attributes('href')).toBe('/products')
    expect(alert.find('button').exists()).toBe(false)
  })
})
